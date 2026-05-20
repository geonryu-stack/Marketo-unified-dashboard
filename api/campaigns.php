<?php
// api/campaigns.php
declare(strict_types=1);
require_once __DIR__ . '/../src/Marketo/MarketoAPI.php';
require_once __DIR__ . '/../src/InternalDB.php';
require_once __DIR__ . '/../src/ScheduleRunner.php';

$method = $_SERVER['REQUEST_METHOD'];
$params = $GLOBALS['route_params'] ?? [];
$id     = $params['id'] ?? null;
$action = $params['action'] ?? null;

try {
    // GET /api/campaigns — 목록
    if ($method === 'GET' && !$id) {
        json_ok(DB::all('SELECT * FROM campaigns ORDER BY created_at DESC'));
    }

    // GET /api/campaigns/{id}
    elseif ($method === 'GET' && $id && !$action) {
        $row = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$row) json_err('캠페인을 찾을 수 없습니다.', 404);
        json_ok($row);
    }

    // GET /api/campaigns/{id}/logs
    elseif ($method === 'GET' && $id && $action === 'logs') {
        json_ok(DB::all('SELECT * FROM job_logs WHERE campaign_id=? ORDER BY created_at ASC', [$id]));
    }

    // GET /api/campaigns/{id}/delivery-result
    elseif ($method === 'GET' && $id && $action === 'delivery-result') {
        $row = DB::one(
            'SELECT sent_count, delivered_count, bounce_count, poll_status,
                    lead_count, activity_polled_at, poll_next_at
             FROM campaigns WHERE id=?',
            [$id]
        );
        if (!$row) json_err('캠페인을 찾을 수 없습니다.', 404);
        json_ok($row);
    }

    // POST /api/campaigns — 생성 후 즉시 테스트 메일 발송
    elseif ($method === 'POST' && !$id) {
        $body    = parse_json_body();
        $send_ts = parse_send_time($body['send_time'] ?? '');
        if (!$send_ts) json_err('발송 일시를 입력하세요.', 400);
        if ($send_ts < time() + 16 * 3600) json_err('발송 일시는 현재 시각으로부터 최소 16시간 이후여야 합니다.', 400);

        $new_id = new_uuid();
        $now    = now_str();
        $seg    = DB::one('SELECT name FROM segments WHERE id=?', [$body['segment_id'] ?? '']);
        DB::exec(
            'INSERT INTO campaigns
             (id, name, segment_id, segment_name, asset_name, reward_url, emoji,
              email_title, email_preheader,
              scheduled_at, send_time, marketo_cloned_email_id,
              status, lead_count, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'draft\',0,?,?)',
            [
                $new_id,
                $body['name'] ?? '',
                $body['segment_id'] ?? '',
                $seg['name'] ?? '',
                $body['asset_name'] ?? '',
                $body['reward_url'] ?? '',
                $body['emoji'] ?? '',
                $body['email_title'] ?? '',
                $body['email_preheader'] ?? '',
                date('Y-m-d H:i:s', $send_ts - 16 * 3600), // 발송 16시간 전 자동 설정
                $body['send_time'] ?? '',
                $body['marketo_cloned_email_id'] ? (string)$body['marketo_cloned_email_id'] : null,
                $now, $now,
            ]
        );
        run_test_email_flow($new_id);
        json_ok(DB::one('SELECT * FROM campaigns WHERE id=?', [$new_id]));
    }

    // POST /api/campaigns/{id}/save — 편집 저장 후 테스트 메일 재발송
    elseif ($method === 'POST' && $id && $action === 'save') {
        $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
        if (!in_array($c['status'], ['awaiting_approval', 'failed', 'draft'])) {
            json_err('편집은 결재 대기·실패·draft 상태일 때만 가능합니다.', 400);
        }

        $body    = parse_json_body();
        $send_ts = parse_send_time($body['send_time'] ?? '');
        if (!$send_ts) json_err('발송 일시를 입력하세요.', 400);
        if ($send_ts < time() + 16 * 3600) json_err('발송 일시는 현재 시각으로부터 최소 16시간 이후여야 합니다.', 400);

        $seg = DB::one('SELECT name FROM segments WHERE id=?', [$body['segment_id'] ?? $c['segment_id']]);
        DB::exec(
            'UPDATE campaigns SET
             name=?, segment_id=?, segment_name=?, asset_name=?, reward_url=?, emoji=?,
             email_title=?, email_preheader=?,
             scheduled_at=?, send_time=?, marketo_cloned_email_id=?,
             error_message=NULL, updated_at=?
             WHERE id=?',
            [
                $body['name']             ?? $c['name'],
                $body['segment_id']       ?? $c['segment_id'],
                $seg['name']              ?? $c['segment_name'],
                $body['asset_name']       ?? $c['asset_name'],
                $body['reward_url']       ?? $c['reward_url'],
                $body['emoji']            ?? $c['emoji'],
                $body['email_title']      ?? $c['email_title'],
                $body['email_preheader']  ?? $c['email_preheader'],
                date('Y-m-d H:i:s', $send_ts - 16 * 3600),
                $body['send_time']        ?? $c['send_time'],
                isset($body['marketo_cloned_email_id']) && $body['marketo_cloned_email_id']
                    ? (string)$body['marketo_cloned_email_id']
                    : $c['marketo_cloned_email_id'],
                now_str(),
                $id,
            ]
        );
        add_log($id, 'edit', 'running', '편집 저장 — 테스트 메일 재발송 시작');
        run_test_email_flow($id);
        json_ok(DB::one('SELECT * FROM campaigns WHERE id=?', [$id]));
    }

    // POST /api/campaigns/{id}/approve — 결재 승인 → 즉시 Marketo 예약 (동기 실행)
    elseif ($method === 'POST' && $id && $action === 'approve') {
        $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
        if ($c['status'] !== 'awaiting_approval') {
            json_err('결재 대기 상태의 캠페인만 승인할 수 있습니다.', 400);
        }

        // CAS: 세그먼트 내 모든 캠페인을 일괄 잠근 후 충돌 검사
        // ORDER BY id 로 일관된 잠금 순서 → 교착(deadlock) 방지
        // 'scheduling' 상태도 충돌로 취급 → 두 탭에서 동시 승인 차단
        $db = DB::get();
        $db->beginTransaction();
        try {
            $seg_rows = DB::all(
                'SELECT id, status, name FROM campaigns WHERE segment_id=? ORDER BY id FOR UPDATE',
                [$c['segment_id']]
            );
            $current  = null;
            $conflict = null;
            foreach ($seg_rows as $row) {
                if ($row['id'] === $id) {
                    $current = $row;
                } elseif (in_array($row['status'], ['scheduled', 'scheduling', 'bulk_polling', 'bulk_finalizing', 'needs_manual_review'], true)) {
                    $conflict = $row;
                }
            }
            if (!$current || $current['status'] !== 'awaiting_approval') {
                $db->rollBack();
                json_err('상태가 변경되었습니다. 페이지를 새로고침 후 다시 시도하세요.', 409);
            }
            if ($conflict) {
                $db->rollBack();
                http_response_code(409);
                header('Content-Type: application/json');
                echo json_encode([
                    'success'       => false,
                    'error'         => "동일 세그먼트 캠페인 \"{$conflict['name']}\"이 예약 처리 중 또는 예약 완료 상태입니다.",
                    'conflict_id'   => $conflict['id'],
                    'conflict_name' => $conflict['name'],
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            DB::exec(
                'UPDATE campaigns SET status=?, approved_at=?, updated_at=? WHERE id=?',
                ['scheduling', now_str(), now_str(), $id]
            );
            $db->commit();
            record_status_transition((string)$id, 'awaiting_approval', 'scheduling', 'user', '운영자 승인');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            json_err($e->getMessage(), 500);
        }

        $c    = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        $logs = [];
        try {
            run_campaign_schedule($c, function(string $step, string $status, string $msg) use ($id, &$logs) {
                add_log($id, $step, $status, $msg);
                $logs[] = "[$step] $msg";
            });
            json_ok(['scheduled' => true, 'log' => $logs]);
        } catch (CampaignNeedsReviewException $e) {
            // status는 이미 'needs_manual_review' (finalize_campaign_schedule 내에서 설정).
            // 'failed'로 덮어쓰지 않음 — sibling 차단 효과 보존.
            add_log($id, 'error', 'error', $e->getMessage());
            json_err($e->getMessage(), 500);
        } catch (Throwable $e) {
            set_campaign_status($id, 'failed', $e->getMessage());
            add_log($id, 'error', 'error', $e->getMessage());
            json_err($e->getMessage(), 500);
        }
    }

    // POST /api/campaigns/{id}/reject — 결재 거절 → draft 복귀
    elseif ($method === 'POST' && $id && $action === 'reject') {
        $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
        if ($c['status'] !== 'awaiting_approval') {
            json_err('결재 대기 상태의 캠페인만 거절할 수 있습니다.', 400);
        }
        $body = parse_json_body();
        $memo = trim((string)($body['reject_memo'] ?? ''));

        // CAS — 동시 승인/거절 차단
        $affected = DB::exec(
            "UPDATE campaigns
                SET status='draft', rejected_at=?, reject_memo=?, error_message=NULL, updated_at=?
              WHERE id=? AND status='awaiting_approval'",
            [now_str(), $memo !== '' ? $memo : null, now_str(), $id]
        );
        if ($affected !== 1) {
            json_err('상태가 이미 변경되었습니다. 페이지를 새로고침 후 확인하세요.', 409);
        }
        record_status_transition((string)$id, 'awaiting_approval', 'draft', 'user', '운영자 거절' . ($memo !== '' ? " (메모: {$memo})" : ''));
        add_log($id, 'reject', 'done', '운영자 거절' . ($memo !== '' ? " (메모: {$memo})" : ''));
        json_ok(['rejected' => true]);
    }

    // POST /api/campaigns/{id}/resend-test-email — 테스트 메일 재발송
    elseif ($method === 'POST' && $id && $action === 'resend-test-email') {
        $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
        if (!in_array($c['status'], ['awaiting_approval', 'draft', 'failed'], true)) {
            json_err('테스트 메일은 결재 대기·draft·실패 상태에서만 재발송 가능합니다.', 400);
        }
        add_log($id, 'resend_test_email', 'running', '운영자가 테스트 메일 재발송 요청');
        run_test_email_flow($id);
        json_ok(DB::one('SELECT * FROM campaigns WHERE id=?', [$id]));
    }

    // POST /api/campaigns/{id}/resolve-review — needs_manual_review 상태 해제
    // 운영자가 Marketo UI 확인 후 명시적으로 'scheduled' 또는 'failed'로 전환.
    // body: {"as": "scheduled"|"failed", "operator_note": "(선택) 결정 근거"}
    elseif ($method === 'POST' && $id && $action === 'resolve-review') {
        $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
        if ($c['status'] !== 'needs_manual_review') {
            json_err('수동 검토 필요 상태의 캠페인만 해제할 수 있습니다.', 400);
        }
        $body = parse_json_body();
        $as   = $body['as'] ?? '';
        if (!in_array($as, ['scheduled', 'failed'], true)) {
            json_err('as 파라미터는 "scheduled" 또는 "failed"여야 합니다.', 400);
        }
        $note = trim((string)($body['operator_note'] ?? ''));

        // 흔적이 남도록 error_message에 결정 내역을 누적 (덮어쓰지 않음)
        $original_err = $c['error_message'] ?? '';
        $resolution   = "[수동 해제 " . now_str() . " → {$as}]"
                      . ($note !== '' ? " 메모: {$note}" : '');
        $new_err      = $original_err === '' ? $resolution : $original_err . "\n" . $resolution;

        // CAS — 동시 해제 시도 방지 (다른 사용자가 먼저 해제했으면 차단)
        $affected = DB::exec(
            "UPDATE campaigns SET status=?, error_message=?, updated_at=?
             WHERE id=? AND status='needs_manual_review'",
            [$as, $new_err, now_str(), $id]
        );
        if ($affected !== 1) {
            json_err('상태가 이미 변경되었습니다. 페이지를 새로고침 후 확인하세요.', 409);
        }
        record_status_transition(
            (string)$id,
            'needs_manual_review',
            $as,
            'user',
            "운영자 결정: {$as}" . ($note !== '' ? " ({$note})" : '')
        );
        add_log($id, 'resolve_review', 'done', "운영자 결정: {$as}" . ($note !== '' ? " ({$note})" : ''));
        json_ok(['status' => $as]);
    }

    // POST /api/campaigns/{id}/cancel — Marketo 예약 취소 → awaiting_approval 복귀
    elseif ($method === 'POST' && $id && $action === 'cancel') {
        $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
        if ($c['status'] !== 'scheduled') json_err('예약된 캠페인만 취소할 수 있습니다.', 400);
        // marketo_email_program_id가 비어있으면 자동 unapprove 불가 → fake cancel 방지.
        // 운영자가 Marketo UI에서 수동 unapprove 후 다른 경로(예: needs_manual_review의 'failed' 해제)
        // 로 정리해야 함.
        if (empty($c['marketo_email_program_id'])) {
            json_err(
                'Marketo Email Program ID가 비어있어 자동 취소 불가. ' .
                'Marketo UI에서 직접 EP를 unapprove한 후, 캠페인을 삭제하거나 신규 캠페인으로 재시작하세요.',
                400
            );
        }
        MarketoAPI::unapproveEmailProgram((int)$c['marketo_email_program_id']);
        DB::exec('UPDATE campaigns SET status=?, marketo_email_program_id=NULL, updated_at=? WHERE id=?',
            ['awaiting_approval', now_str(), $id]);
        record_status_transition((string)$id, 'scheduled', 'awaiting_approval', 'user', '운영자 예약 취소 (EP unapprove)');
        json_ok(['cancelled' => true]);
    }

    // POST /api/campaigns/{id}/duplicate — 복제 + 테스트 메일 즉시 발송
    elseif ($method === 'POST' && $id && $action === 'duplicate') {
        $src = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$src) json_err('캠페인을 찾을 수 없습니다.', 404);

        $raw_st  = $src['send_time'] ?? '';
        $time_hm = strlen($raw_st) > 5 ? date('H:i', strtotime($raw_st)) : ($raw_st ?: '10:00');
        // scheduled_at(=send_time-16h)이 현재보다 미래가 될 때까지 하루씩 순방향 탐색
        $send_ts = strtotime(date('Y-m-d', strtotime('+1 day')) . 'T' . $time_hm);
        while ($send_ts - 16 * 3600 < time()) {
            $send_ts += 86400;
        }
        $new_send_time = date('Y-m-d\TH:i', $send_ts);
        $new_id        = new_uuid();
        $now           = now_str();
        $new_name      = preg_replace('/\d{4}-\d{2}-\d{2}/', date('Y-m-d'), $src['name']);

        DB::exec(
            'INSERT INTO campaigns
             (id, name, segment_id, segment_name, asset_name, reward_url, emoji,
              email_title, email_preheader,
              scheduled_at, send_time, marketo_cloned_email_id,
              status, lead_count, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'draft\',0,?,?)',
            [
                $new_id, $new_name,
                $src['segment_id'], $src['segment_name'],
                $src['asset_name'], $src['reward_url'], $src['emoji'] ?? '',
                $src['email_title'] ?? '', $src['email_preheader'] ?? '',
                date('Y-m-d H:i:s', $send_ts - 16 * 3600),
                $new_send_time,
                $src['marketo_cloned_email_id'],
                $now, $now,
            ]
        );
        run_test_email_flow($new_id);
        json_ok(['id' => $new_id]);
    }

    // DELETE /api/campaigns/{id}
    elseif ($method === 'DELETE' && $id && !$action) {
        DB::exec('DELETE FROM campaigns WHERE id=?', [$id]);
        DB::exec('DELETE FROM job_logs WHERE campaign_id=?', [$id]);
        json_ok(null);
    }

    else {
        json_err('Not Found', 404);
    }

} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}

// ── 테스트 메일 플로우 (생성/편집 즉시 실행) ─────────────────────

function run_test_email_flow(string $id): void
{
    $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);

    try {
        $lib_program_id = (defined('MARKETO_EMAIL_ASSET_LIBRARY_ID') && (int)MARKETO_EMAIL_ASSET_LIBRARY_ID > 0)
            ? (int)MARKETO_EMAIL_ASSET_LIBRARY_ID : 0;
        if ($lib_program_id) {
            add_log($id, 'inject_tokens', 'running', "Library Program($lib_program_id) My Token 주입 시작");
            MarketoAPI::syncProgramMyTokens($lib_program_id, build_campaign_tokens($c));
            add_log($id, 'inject_tokens', 'done', '4개 토큰 주입 완료 [Emoji, Title, Preheader, RewardUrl]');
        } else {
            add_log($id, 'inject_tokens', 'done', 'MARKETO_EMAIL_ASSET_LIBRARY_ID 미설정 — 토큰 주입 생략');
        }

        $test_emails = array_values(array_filter(array_map('trim', explode(',', SEND_TEST_EMAIL_TO))));
        if (empty($test_emails)) throw new RuntimeException('SEND_TEST_EMAIL_TO 환경 설정 누락');

        $email_asset_id = (int)($c['marketo_cloned_email_id'] ?? 0);
        if (!$email_asset_id) throw new RuntimeException('이메일 에셋 ID 미설정 — 캠페인 편집에서 에셋을 선택하세요.');

        add_log($id, 'send_test_email', 'running', '테스트 메일 발송: ' . implode(', ', $test_emails));
        foreach ($test_emails as $addr) {
            MarketoAPI::sendSampleEmail($email_asset_id, $addr);
        }
        add_log($id, 'send_test_email', 'done', '테스트 메일 발송 완료');

        set_campaign_status($id, 'awaiting_approval');

    } catch (Throwable $e) {
        set_campaign_status($id, 'failed', $e->getMessage());
        add_log($id, 'error', 'error', $e->getMessage());
        // 실패해도 캠페인은 생성됨 (failed 상태로 편집 후 재시도 가능)
    }
}

// ── 공통 헬퍼 ────────────────────────────────────────────────────

function set_campaign_status(string $id, string $status, ?string $error = null): void
{
    DB::exec('UPDATE campaigns SET status=?, error_message=?, updated_at=? WHERE id=?',
        [$status, $error, now_str(), $id]);
}

function add_log(string $campaign_id, string $step, string $status, string $message): void
{
    DB::exec(
        'INSERT INTO job_logs (id, campaign_id, step, status, message, created_at) VALUES (?,?,?,?,?,?)',
        [new_uuid(), $campaign_id, $step, $status, $message, now_str()]
    );
}
