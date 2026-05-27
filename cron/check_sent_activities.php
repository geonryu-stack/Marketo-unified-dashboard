<?php
// cron/check_sent_activities.php — Marketo Activity API로 캠페인 발송 결과 수집
// 실행 주기: 5분 (Windows Task Scheduler)
declare(strict_types=1);

define('RUNNING_AS_CLI', true);
chdir(dirname(__DIR__));

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Marketo/MarketoAPI.php';
require_once __DIR__ . '/../src/SendCap.php';
require_once __DIR__ . '/../src/Notifier.php';

job_log('Activity 폴링 cron 시작');

$due = DB::all(
    "SELECT c.*, s.marketo_audience_list_id
     FROM campaigns c
     JOIN segments s ON c.segment_id = s.id
     WHERE c.poll_status = 'polling' AND c.poll_next_at <= ?
     ORDER BY c.poll_next_at ASC
     LIMIT 10",
    [now_str()]
);

job_log(count($due) . '건 처리 시작');

foreach ($due as $c) {
    job_log("  [{$c['name']}]");
    try {
        $list_id = (int)$c['marketo_audience_list_id'];
        if (!$list_id) {
            job_log('    ⚠ audience_list_id 미설정 — 건너뜀');
            continue;
        }

        // send_time 기준 24시간 전부터 조회 (RTZ로 앞선 timezone 활동도 포함).
        //
        // SEV1 RCA(2026-05-22) 후속 — 본 since 계산도 KST→UTC 명시 변환 필요. 과거 코드는
        // strtotime() 가 시스템 TZ(KST)로 epoch 를 만든 뒤 date() 가 다시 KST 로 포맷하고 'Z' 리터럴만
        // 부착했음 → sinceDatetime 이 *9시간 미래값* 으로 Marketo 에 전달되어, 발송 직후 첫 9시간 분량의
        // sent/delivered/open 활동을 누락 (담당자 검수 C2). format_send_time_for_marketo 와 동일 변환
        // 후 -1일 적용.
        try {
            $send_dt_utc = format_send_time_for_marketo((string)($c['send_time'] ?? ''));
        } catch (RuntimeException $e) {
            job_log('    ⚠ send_time 파싱 실패 — 건너뜀: ' . $e->getMessage());
            continue;
        }
        $send_ts_utc = strtotime($send_dt_utc);
        $since       = gmdate('Y-m-d\TH:i:s\Z', $send_ts_utc - 86400);

        // PR-2 + 발송결과 MVP — maxPages 캡 + 이어받기 + open/click/unsubscribe 확장.
        // typeIds 는 MarketoAPI::ENGAGEMENT_TYPE_IDS 상수에서 가져와 통일.
        // (운영자 인스턴스에서 ID 매핑이 다르더라도 상수 한 곳만 변경하면 cron 까지 자동 반영)
        $max_pages = defined('MARKETO_ACTIVITY_MAX_PAGES_PER_CRON')
            ? (int)MARKETO_ACTIVITY_MAX_PAGES_PER_CRON : 0;
        $resume = $c['activity_next_token'] ?? null;
        $type_ids = array_values(MarketoAPI::engagementTypeIds());
        $result = MarketoAPI::getActivitiesPaginated(
            $list_id, $since, $type_ids, $resume, $max_pages
        );
        $acts       = $result['activities'];
        $truncated  = $result['truncated'];
        $next_token = $result['next_token'];
        $pages      = $result['pages'];
        if ($truncated) {
            job_log("    ⚠ Activity 폴링 maxPages({$max_pages}) 도달 — 다음 cron 에서 이어받기");
        }

        // ── L2 시계열을 단일 진실(single source of truth)로 처리 ────
        // resume($resume 있음): 이번 cron 은 이어받기 → += delta
        // 새 폴링($resume 없음): since 부터 전체 다시 폴링 → 절대값 덮어쓰기
        // L1 (campaigns.{sent_count,...}) 은 L2 SUM 으로 cron 끝에 재계산 (Critical #1 정합성 보장)
        $tally     = MarketoAPI::tallyEngagement($acts); // 로그 출력용
        $by_date   = MarketoAPI::tallyEngagementByDate($acts);
        $is_resume = ($resume !== null && $resume !== '');
        $now_str   = now_str();

        // ── 리드별 cap — sent activity 의 leadId/email 로 lead_send_history confirm ─
        // sent(typeId=6) 만 추출. leadId 우선, 이메일은 fallback. 멱등 (이미 sent 이면 confirmed_at 만 갱신).
        $targets = SendCap::extractSentTargets($acts);
        if (!empty($targets['lead_ids']) || !empty($targets['emails'])) {
            SendCap::confirmSent((string)$c['id'], $targets['lead_ids'], $targets['emails']);
            $confirmed_n = count($targets['lead_ids']) + count($targets['emails']);
            job_log("    SendCap confirm: leadId {$confirmed_n}건 sent 박제");
        }

        // ── SEV1 RCA(2026-05-22) 후속 — 발송 자산명 사후 검증 (RC #2 B-2) ─────
        // Marketo Smart Campaign Flow 의 Send Email 스텝에 박힌 이메일을 *API 로 변경할 수 없으므로*,
        // 사후라도 *실제 발송된 이메일 자산 이름* 을 Activity API 로 가져와 본 시스템의
        // campaigns.asset_name(=운영자 의도) 과 비교한다.
        //
        // **mismatch 판정 정책 (Codex review 반영)**:
        //   발송 자산 집합에 *의도 자산이 아닌 자산이 1건이라도 섞여 있으면 mismatch*.
        //   - 단일 자산이 의도와 다름 → mismatch (격리)
        //   - 다중 자산(mixed) — 의도 자산 + 다른 자산 → **mismatch (격리)** ← in_array 단독으로는 못 잡던 버그
        //   - 의도 자산만 정확히 발송 → 정상
        //   - 의도 자산이 아예 발송 안 됨(모두 다른 자산) → mismatch
        //
        // 멱등: 이미 needs_manual_review 인 캠페인은 status 재변경 안 함. error_message 도 누적 X (한 번 박제).
        // M-asset-mismatch 보강 (담당자 검수) — 본 캠페인의 SC/EP ID 로 필터링해 sibling 캠페인의
        // sent activity 가 같은 listId 윈도우에 섞여 false-positive 격리되는 것을 차단.
        // marketo_id 가 0/음수면 (e.g. 발송 직후 ID 클리어된 캠페인) 전체 윈도우 폴백.
        $marketo_id_for_filter = (int)($c['marketo_email_program_id'] ?? 0);
        $sent_asset_names = MarketoAPI::extractSentEmailAssetNamesForCampaign($acts, $marketo_id_for_filter);
        $expected_asset   = trim((string)($c['asset_name'] ?? ''));
        if (!empty($sent_asset_names) && $expected_asset !== '' && $c['status'] !== 'needs_manual_review') {
            // 정책 판정은 MarketoAPI::detectAssetNameMismatch 에 위임 — mixed 케이스 포함 단위 테스트 가능.
            $detect            = MarketoAPI::detectAssetNameMismatch($sent_asset_names, $expected_asset);
            $is_mismatch       = $detect['mismatch'];
            $unexpected_assets = $detect['unexpected'];
            if ($is_mismatch) {
                $actual_str     = implode(' / ', $sent_asset_names);
                $unexpected_str = implode(' / ', $unexpected_assets);
                $err_msg = "[SEV1] 발송 자산명 불일치 — 의도: '{$expected_asset}', 실제 Marketo 발송: '{$actual_str}', "
                         . "의도 외 자산: '{$unexpected_str}'. "
                         . "Smart Campaign Flow 가 의도와 다른 이메일을 가리키고 있었거나 Flow choice/branch 가 "
                         . "다른 자산을 분기시켰을 가능성. Marketo UI 에서 SC Flow 확인 후 needs_manual_review 해제하세요.";
                DB::exec(
                    "UPDATE campaigns SET status='needs_manual_review',
                                          error_message = CONCAT(IFNULL(error_message, ''), CASE WHEN error_message IS NULL OR error_message = '' THEN '' ELSE '\n\n' END, ?),
                                          updated_at=?
                       WHERE id=? AND status <> 'needs_manual_review'",
                    [$err_msg, $now_str, $c['id']]
                );
                job_log("    ⚠ SEV1 자산 불일치 — needs_manual_review 격리. {$err_msg}");
                Notifier::slack(
                    "🚨 [SEV1 자산 불일치] 캠페인 '{$c['name']}' — 의도 '{$expected_asset}' vs 실제 '{$actual_str}' (의도 외: '{$unexpected_str}'). needs_manual_review 격리됨.",
                    'crit'
                );
            }
        }
        foreach ($by_date as $stat_date => $d) {
            if ($is_resume) {
                DB::exec(
                    'INSERT INTO campaign_daily_stats
                     (campaign_id, stat_date, sent, delivered, bounce, open, click, unsubscribe, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                       sent        = sent + VALUES(sent),
                       delivered   = delivered + VALUES(delivered),
                       bounce      = bounce + VALUES(bounce),
                       open        = open + VALUES(open),
                       click       = click + VALUES(click),
                       unsubscribe = unsubscribe + VALUES(unsubscribe),
                       updated_at  = VALUES(updated_at)',
                    [$c['id'], $stat_date,
                     $d['sent'], $d['delivered'], $d['bounce'],
                     $d['open'], $d['click'], $d['unsubscribe'],
                     $now_str]
                );
            } else {
                DB::exec(
                    'INSERT INTO campaign_daily_stats
                     (campaign_id, stat_date, sent, delivered, bounce, open, click, unsubscribe, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                       sent=VALUES(sent), delivered=VALUES(delivered), bounce=VALUES(bounce),
                       open=VALUES(open), click=VALUES(click), unsubscribe=VALUES(unsubscribe),
                       updated_at=VALUES(updated_at)',
                    [$c['id'], $stat_date,
                     $d['sent'], $d['delivered'], $d['bounce'],
                     $d['open'], $d['click'], $d['unsubscribe'],
                     $now_str]
                );
            }
        }

        // ── L1 재계산: L2 SUM 으로 절대값 산출 (정합성 보장) ─────
        $agg = DB::one(
            'SELECT
                COALESCE(SUM(sent),0)        AS sent,
                COALESCE(SUM(delivered),0)   AS delivered,
                COALESCE(SUM(bounce),0)      AS bounce,
                COALESCE(SUM(open),0)        AS open_sum,
                COALESCE(SUM(click),0)       AS click_sum,
                COALESCE(SUM(unsubscribe),0) AS unsub_sum
               FROM campaign_daily_stats WHERE campaign_id = ?',
            [$c['id']]
        ) ?? [];
        $sent      = (int)($agg['sent']      ?? 0);
        $delivered = (int)($agg['delivered'] ?? 0);
        $bounce    = (int)($agg['bounce']    ?? 0);
        $open      = (int)($agg['open_sum']  ?? 0);
        $click     = (int)($agg['click_sum'] ?? 0);
        $unsub     = (int)($agg['unsub_sum'] ?? 0);

        // ── last_activity_at: activities 의 실제 최대 activityDate ISO 사용 (Should #5 — grain 정확) ─
        $last_activity_at = $c['last_activity_at'] ?? null;
        foreach ($acts as $a) {
            $iso = (string)($a['activityDate'] ?? '');
            if ($iso === '') continue;
            $ts = strtotime($iso);
            if (!$ts) continue;
            $iso_norm = date('Y-m-d H:i:s', $ts);
            if ($last_activity_at === null || $iso_norm > $last_activity_at) {
                $last_activity_at = $iso_norm;
            }
        }

        // ── elapsed 계산: poll_started_at null 방어 (Should #6) ─────
        // null/empty 면 send_time 시각으로 fallback. 그것도 없으면 이번 cron 시작을 기준.
        $started_at = $c['poll_started_at'] ?? '';
        if ($started_at === '' || strtotime($started_at) === false) {
            $started_at = $c['send_time'] ?? '';
        }
        $start_ts = $started_at ? strtotime($started_at) : time();
        if (!$start_ts) $start_ts = time();
        $elapsed_min = (time() - $start_ts) / 60;
        $lead_count  = (int)$c['lead_count'];
        $coverage    = $lead_count > 0 ? $sent / $lead_count : 0;

        // 종료 조건 변경 (8h → 168h) — open/click 은 D+7 까지 트리클하므로 일찍 종료하면 누락.
        // 신규 활동 1h 이상 없음 + 발송 후 168h 경과 = 진짜 종료. truncated 면 절대 종료 안 함.
        $now_ts             = time();
        $stale_threshold_s  = 3600; // 1h
        $last_act_ts        = $last_activity_at ? strtotime($last_activity_at) : 0;
        $has_recent_activity = $last_act_ts > 0 && ($now_ts - $last_act_ts) < $stale_threshold_s;
        $is_done = !$truncated
            && (
                ($elapsed_min >= 168 * 60)                              // 168h 초과 → 강제 종료
                || ($coverage >= 0.95 && !$has_recent_activity)          // 발송은 95%+ 이고 트리클 끝남
            );

        $new_status = $is_done
            ? ($coverage >= 0.9 ? 'done' : 'timeout')
            : 'polling';

        // 폴링 간격 — truncated 면 1분(즉시 따라잡기), 발송 직후 빠르게, 이후 점진 backoff
        $next_interval = $truncated ? 1 : match(true) {
            $elapsed_min < 60   => 5,
            $elapsed_min < 240  => 15,
            $elapsed_min < 1440 => 60,    // 1일 내: 1시간
            default             => 240,   // 그 이후: 4시간 (open/click 트리클)
        };
        $poll_next = $is_done ? null : date('Y-m-d H:i:s', $now_ts + $next_interval * 60);
        $now       = now_str();

        DB::exec(
            "UPDATE campaigns SET
             sent_count=?, delivered_count=?, bounce_count=?,
             open_count=?, click_count=?, unsubscribe_count=?, last_activity_at=?,
             poll_status=?, poll_next_at=?, activity_polled_at=?, activity_next_token=?, updated_at=?
             WHERE id=?",
            [$sent, $delivered, $bounce, $open, $click, $unsub, $last_activity_at,
             $new_status, $poll_next, $now, $next_token, $now, $c['id']]
        );

        $extra = $truncated ? " truncated pages={$pages}" : " pages={$pages}";
        job_log("    Δ sent={$tally['sent']} delivered={$tally['delivered']} bounce={$tally['bounce']} open={$tally['open']} click={$tally['click']} unsub={$tally['unsubscribe']} → total sent={$sent} open={$open} click={$click}{$extra} → {$new_status}");
    } catch (Throwable $e) {
        job_log('    ✗ ' . $e->getMessage());
    }
}

job_log('완료');
