<?php
// cron/check_bulk_imports.php — Bulk Import 잡 상태 폴링 후 완료 시 다음 단계 진행
// 실행 주기: 1분 (Bulk Import는 통상 1~5분 소요)
declare(strict_types=1);

define('RUNNING_AS_CLI', true);
chdir(dirname(__DIR__));

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Marketo/MarketoAPI.php';
require_once __DIR__ . '/../src/Marketo/MarketoBulkImport.php';
require_once __DIR__ . '/../src/ScheduleRunner.php'; // CampaignNeedsReviewException 포함
require_once __DIR__ . '/../src/SendCap.php';        // fail 분기에서 stale hold 정리용

/**
 * Bulk Import 폴링 안에서 *발송 안 일어남이 확정된* fail 분기들이 공유하는 정리.
 *
 * Codex stop-time review (2026-05-27) — bulk fail 시 SendCap::clearForCampaign 누락 →
 * 60K hold 가 영구 stale 로 남아 미래 캠페인의 동일 이메일이 cap 위반으로 부당 차단.
 *
 * 정책: bulk_polling 진입 = 추출 + hold 박제 완료. fail 확정 시 *발송은 안 일어남* 이
 * 보장되므로 hold 즉시 정리 안전 (sent 박제는 애초에 없음). VVIP Suppression 도 동일.
 */
function _mark_bulk_failed(string $campaign_id, string $err_msg, ?string $run_id): void
{
    DB::exec(
        "UPDATE campaigns SET status='failed', error_message=?, updated_at=? WHERE id=?",
        [$err_msg, now_str(), $campaign_id]
    );
    Suppression::clearForCampaign($campaign_id);
    SendCap::clearForCampaign($campaign_id);
}

job_log('Bulk Import 폴링 cron 시작');

// 폴링 대상: bulk_polling 상태 캠페인 최대 10건
$due = DB::all(
    "SELECT * FROM campaigns
     WHERE status = 'bulk_polling' AND bulk_job_id IS NOT NULL
     ORDER BY bulk_started_at ASC LIMIT 10"
);

job_log(count($due) . '건 처리 시작');

foreach ($due as $c) {
    $id      = $c['id'];
    $name    = $c['name'];
    $batchId = (string)$c['bulk_job_id'];

    job_log("  [{$name}] batchId={$batchId}");
    try {
        $status_resp = MarketoBulkImport::getBulkImportStatus($batchId);
        $job_status  = (string)($status_resp['status'] ?? 'Unknown');
        $err_count   = (int)($status_resp['numOfRowsWithError'] ?? 0);
        $failed      = (int)($status_resp['numOfRowsFailed'] ?? 0);

        job_log("    status={$job_status} errors={$err_count} failed={$failed}");

        // Sprint 3 ORCH/MKT — computeProgress 가 정의돼 있으면 rows/sec + ETA 까지 로그.
        // bulk_status 컬럼 자체는 'Importing/Complete/Failed' 만 유지 (VARCHAR(20) 호환).
        // 정밀 데이터는 detail.php가 GET /api/campaigns/{id}/bulk-progress 로 직접 가져옴.
        if (method_exists('MarketoBulkImport', 'computeProgress')) {
            try {
                $prog = MarketoBulkImport::computeProgress($status_resp, $c['bulk_started_at'] ?? null);
                $pct  = $prog['progress_pct'] ?? 0;
                $rps  = $prog['rows_per_sec'] ?? 0;
                $eta  = $prog['eta_sec']      ?? null;
                $etaStr = $eta === null ? '?' : (int)$eta . 's';
                job_log("    progress={$pct}% rps={$rps} eta={$etaStr}");
            } catch (Throwable $_e) {
                // 진행률 산출 실패는 본업 방해 금지 — 무시.
            }
        }

        // bulk_status 컬럼은 진행 중에도 갱신 (UI 폴링용)
        DB::exec(
            "UPDATE campaigns SET bulk_status=?, updated_at=? WHERE id=?",
            [$job_status, now_str(), $id]
        );

        if ($job_status === 'Importing' || $job_status === 'Queued') {
            // 아직 진행 중 — 다음 cron 주기까지 대기
            continue;
        }

        if ($job_status === 'Failed') {
            $msg = "Bulk Import 실패 (errors={$err_count}, failed={$failed})";
            _mark_bulk_failed((string)$id, $msg, $c['run_id'] ?? null);
            record_status_transition((string)$id, 'bulk_polling', 'failed', 'cron', $msg, $c['run_id'] ?? null);
            job_log($msg, $id, 'bulk_submit', 'error');
            job_log("    → failed");
            continue;
        }

        if ($job_status === 'Complete') {
            // ── 부분 발송 차단 ─────────────────────────────────────
            // numOfRowsFailed > 0 = 일부 leads가 list에 등록되지 않음.
            // 그대로 EP 예약하면 의도한 명수보다 적게 발송 → 운영자 판단 필요.
            if ($failed > 0) {
                $msg = "Bulk Import 부분 실패 ({$failed}명 누락) — 자동 진행 차단. " .
                       "Marketo UI에서 실제 누락 leads 확인 후 캠페인을 다시 예약하거나 수동 보정하세요.";
                _mark_bulk_failed((string)$id, $msg, $c['run_id'] ?? null);
                record_status_transition((string)$id, 'bulk_polling', 'failed', 'cron', $msg, $c['run_id'] ?? null);
                job_log($msg, $id, 'bulk_submit', 'error');
                job_log("    → failed (부분 실패 {$failed}명)");
                continue;
            }

            // ── 중복 finalize 차단 (CAS) ────────────────────────────
            // bulk_polling → bulk_finalizing 원자적 전환. 1개 row만 affected이면 우리가 잡았음.
            // 동시 실행 중인 다른 cron 인스턴스 또는 재실행 시 race condition 방지.
            $claimed = DB::exec(
                "UPDATE campaigns SET status='bulk_finalizing', updated_at=?
                 WHERE id=? AND status='bulk_polling'",
                [now_str(), $id]
            );
            if ($claimed !== 1) {
                job_log("    ⚠ 다른 cron이 이미 처리 중 (status가 bulk_polling이 아님) — 건너뜀");
                continue;
            }
            record_status_transition((string)$id, 'bulk_polling', 'bulk_finalizing', 'cron', 'Bulk Import Complete', $c['run_id'] ?? null);
            job_log("Bulk Import 완료 (errors={$err_count}, failed=0)", $id, 'bulk_submit', 'done');

            $seg = DB::one('SELECT * FROM segments WHERE id=?', [$c['segment_id']]);
            if (!$seg) {
                $err = '연결된 세그먼트를 찾을 수 없습니다.';
                _mark_bulk_failed((string)$id, $err, $c['run_id'] ?? null);
                record_status_transition((string)$id, 'bulk_finalizing', 'failed', 'cron', $err, $c['run_id'] ?? null);
                job_log($err, $id, 'schedule_ep', 'error');
                job_log("    ✗ {$err}");
                continue;
            }

            // CAS 후 status는 bulk_finalizing이므로 $c에도 반영해서 finalize에 전달
            $c['status'] = 'bulk_finalizing';
            try {
                finalize_campaign_schedule($c, $seg, function(string $step, string $status, string $msg) use ($id) {
                    job_log($msg, $id, $step, $status);
                });
                job_log("    → scheduled");
            } catch (CampaignNeedsReviewException $e) {
                // EP 변경 도중 실패 — finalize_campaign_schedule이 이미 'needs_manual_review' 설정.
                // 'failed'로 덮어쓰지 않음 (sibling 차단 효과 보존).
                // Slack 알림은 finalize_campaign_schedule() 내부에서 throw 직전에 이미 발사됐지만,
                // 만일을 위해 cron 분기에서도 재발사 (Notifier가 dedupe/throttle 책임).
                Notifier::slack("⚠️ 캠페인 [{$c['name']}] needs_manual_review — " . $e->getMessage(), 'critical');
                job_log($e->getMessage(), $id, 'schedule_ep', 'error');
                job_log("    ✗ NEEDS_REVIEW: " . $e->getMessage());
            } catch (Throwable $e) {
                // EP 진입 전 실패 (Program ID 미설정 등) — EP 미변경이라 'failed'로 풀어도 안전.
                $err = "Bulk 완료 후 EP 예약 진입 전 실패: " . $e->getMessage();
                _mark_bulk_failed((string)$id, $err, $c['run_id'] ?? null);
                record_status_transition((string)$id, 'bulk_finalizing', 'failed', 'cron', $err, $c['run_id'] ?? null);
                job_log($err, $id, 'schedule_ep', 'error');
                job_log("    ✗ {$err}");
            }
            continue;
        }

        // 알 수 없는 상태
        job_log("    ⚠ 알 수 없는 status={$job_status} — 다음 cron까지 대기");
    } catch (Throwable $e) {
        // 폴링 자체가 실패한 경우 — DB 상태는 그대로 유지하고 다음 cron에서 재시도
        job_log("    ✗ 폴링 오류 (다음 cron에서 재시도): " . $e->getMessage());
    }
}

job_log('완료');
