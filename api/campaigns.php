<?php
// api/campaigns.php
declare(strict_types=1);
require_once __DIR__ . '/../src/Marketo/MarketoAPI.php';
require_once __DIR__ . '/../src/Marketo/MarketoBulkImport.php';
require_once __DIR__ . '/../src/InternalDB.php';
require_once __DIR__ . '/../src/ScheduleRunner.php';
require_once __DIR__ . '/../src/Suppression.php';
require_once __DIR__ . '/../src/SendCap.php';

api_handle(function (string $method, ?string $id, ?string $action, array $params): void {
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

    // GET /api/campaigns/{id}/previous-cohort — Sprint 2 DB (안정 API)
    // 본 캠페인의 같은 segment 내 직전 sent 회차 1건을 요약 반환. 없으면 previous: null.
    elseif ($method === 'GET' && $id && $action === 'previous-cohort') {
        $row = DB::one(
            'SELECT c2.id, c2.name, c2.send_time,
                    c2.lead_count, c2.sent_count, c2.delivered_count, c2.bounce_count
               FROM campaigns c1
               JOIN campaigns c2 ON c2.segment_id = c1.segment_id
              WHERE c1.id = ?
                AND c2.id != c1.id
                AND c2.status = ?
                AND c2.created_at < c1.created_at
              ORDER BY c2.created_at DESC
              LIMIT 1',
            [$id, 'sent']
        );
        if (!$row) {
            json_ok(['previous' => null]);
        }
        json_ok(['previous' => compute_cohort_stats($row)]);
    }

    // GET /api/campaigns/{id}/bulk-progress — Sprint 3 ORCH
    elseif ($method === 'GET' && $id && $action === 'bulk-progress') {
        $row = DB::one(
            'SELECT id, status, bulk_job_id, bulk_status, bulk_started_at, lead_count
               FROM campaigns WHERE id=?',
            [$id]
        );
        if (!$row) json_err('캠페인을 찾을 수 없습니다.', 404);

        $batch_id   = (string)($row['bulk_job_id'] ?? '');
        $started_at = $row['bulk_started_at'] ?? null;

        if ($row['status'] !== 'bulk_polling' || $batch_id === '') {
            json_ok([
                'status'       => (string)($row['bulk_status'] ?? $row['status']),
                'processed'    => 0,
                'total'        => (int)($row['lead_count'] ?? 0),
                'failed'       => 0,
                'progress_pct' => 0.0,
                'rows_per_sec' => 0.0,
                'eta_sec'      => null,
                'elapsed_sec'  => 0,
                'started_at'   => $started_at,
                'batch_id'     => $batch_id,
                'campaign_status' => (string)$row['status'],
                'available'    => false,
            ]);
        }

        try {
            $status_resp = MarketoBulkImport::getBulkImportStatus($batch_id);
        } catch (Throwable $e) {
            json_ok([
                'status'       => (string)($row['bulk_status'] ?? 'Importing'),
                'processed'    => 0,
                'total'        => (int)($row['lead_count'] ?? 0),
                'failed'       => 0,
                'progress_pct' => 0.0,
                'rows_per_sec' => 0.0,
                'eta_sec'      => null,
                'elapsed_sec'  => 0,
                'started_at'   => $started_at,
                'batch_id'     => $batch_id,
                'campaign_status' => 'bulk_polling',
                'available'    => false,
                'error'        => $e->getMessage(),
            ]);
        }

        if (method_exists('MarketoBulkImport', 'computeProgress')) {
            $progress = MarketoBulkImport::computeProgress($status_resp, $started_at);
        } else {
            $imported = (int)($status_resp['numOfLeadsProcessed'] ?? $status_resp['numOfRowsImported'] ?? 0);
            $failed   = (int)($status_resp['numOfRowsFailed'] ?? 0);
            $total    = (int)($row['lead_count'] ?? 0);
            $elapsed  = $started_at ? max(0, time() - strtotime($started_at)) : 0;
            $pct      = $total > 0 ? min(100.0, ($imported / $total) * 100.0) : 0.0;
            $rps      = $elapsed > 0 ? round($imported / $elapsed, 2) : 0.0;
            $eta      = ($rps > 0 && $total > $imported) ? (int)round(($total - $imported) / $rps) : null;
            $progress = [
                'status'       => (string)($status_resp['status'] ?? 'Importing'),
                'processed'    => $imported,
                'total'        => $total,
                'failed'       => $failed,
                'progress_pct' => round($pct, 1),
                'rows_per_sec' => $rps,
                'eta_sec'      => $eta,
                'elapsed_sec'  => $elapsed,
            ];
        }

        $progress['started_at']      = $started_at;
        $progress['batch_id']        = $batch_id;
        $progress['campaign_status'] = (string)$row['status'];
        $progress['available']       = true;
        json_ok($progress);
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
        assert_campaign_input($body); // C-INPUT-SANITY
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
        assert_campaign_input($body); // C-INPUT-SANITY
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
        handle_approve($id, parse_json_body());
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

    // POST /api/campaigns/{id}/screenshot — 결재 카드 테스트 메일 스크린샷 첨부
    elseif ($method === 'POST' && $id && $action === 'screenshot') {
        $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
        if ($c['status'] !== 'awaiting_approval') {
            json_err('테스트 메일 스크린샷은 결재 대기 상태에서만 첨부할 수 있습니다.', 400);
        }
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
            json_err('첨부 파일이 없습니다.', 400);
        }
        if (!empty($_FILES['file']['error'])) {
            json_err('파일 업로드 오류 (code=' . (int)$_FILES['file']['error'] . ')', 400);
        }

        try {
            $rel_path = screenshot_save(
                $_FILES['file']['tmp_name'],
                $c['id'],
                (string)$_FILES['file']['name']
            );
        } catch (RuntimeException $e) {
            json_err($e->getMessage(), 400);
        }

        DB::exec(
            'UPDATE campaigns SET test_screenshot_path=?, updated_at=? WHERE id=?',
            [$rel_path, now_str(), $id]
        );
        add_log($id, 'screenshot', 'done', '운영자가 테스트 메일 스크린샷 첨부: ' . $rel_path);
        json_ok([
            'path'          => $rel_path,
            'thumbnail_url' => rtrim(APP_URL, '/') . '/' . ltrim($rel_path, '/'),
        ]);
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

        $original_err = $c['error_message'] ?? '';
        $resolution   = "[수동 해제 " . now_str() . " → {$as}]"
                      . ($note !== '' ? " 메모: {$note}" : '');
        $new_err      = $original_err === '' ? $resolution : $original_err . "\n" . $resolution;

        // CAS — 동시 해제 시도 방지
        $affected = DB::exec(
            "UPDATE campaigns SET status=?, error_message=?, updated_at=?
             WHERE id=? AND status='needs_manual_review'",
            [$as, $new_err, now_str(), $id]
        );
        if ($affected !== 1) {
            json_err('상태가 이미 변경되었습니다. 페이지를 새로고침 후 확인하세요.', 409);
        }

        if ($as === 'failed') {
            Suppression::clearForCampaign((string)$id);
            SendCap::clearForCampaign((string)$id);
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
        handle_cancel($id);
    }

    // POST /api/campaigns/{id}/duplicate — 복제 + 테스트 메일 즉시 발송
    elseif ($method === 'POST' && $id && $action === 'duplicate') {
        handle_duplicate($id);
    }

    // DELETE /api/campaigns/{id}
    elseif ($method === 'DELETE' && $id && !$action) {
        // L2: DELETE 전에 현재 status 조회 → status_transition 기록
        $before = DB::one('SELECT status FROM campaigns WHERE id=?', [$id]);
        $prev_status = $before['status'] ?? null;

        DB::exec('DELETE FROM campaigns WHERE id=?', [$id]);
        DB::exec('DELETE FROM job_logs WHERE campaign_id=?', [$id]);
        Suppression::clearForCampaign((string)$id);
        SendCap::clearForCampaign((string)$id);

        record_status_transition((string)$id, $prev_status, 'deleted', 'user', '캠페인 삭제');
        json_ok(null);
    }

    else {
        json_err('Not Found', 404);
    }
});

// ── 핸들러 함수 (Phase 4 추출) ───────────────────────────────────

function handle_approve(string $id, array $approve_body): void
{
    $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
    if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
    if ($c['status'] !== 'awaiting_approval') {
        json_err('결재 대기 상태의 캠페인만 승인할 수 있습니다.', 400);
    }

    // 결재 체크리스트 서버측 강제 게이트.
    $confirmations = $approve_body['confirmations'] ?? null;
    if (!is_array($confirmations)) {
        json_err('결재 체크리스트 확인 신호(confirmations)가 객체 형식이어야 합니다.', 400);
    }
    $required_confirmations = [
        'tokens'        => '토큰 4종 값 확인',
        'sendtime'      => '발송 일시 확인',
        'leadcount'     => '대상자 세그먼트 확인',
        'testmail'      => '테스트 메일 렌더링 확인',
        'marketo_asset' => 'Marketo UI Send Email 에셋 확인',
    ];
    $missing = [];
    foreach ($required_confirmations as $key => $label) {
        if (!array_key_exists($key, $confirmations) || $confirmations[$key] !== true) {
            $missing[] = $label;
        }
    }
    if (!empty($missing)) {
        json_err(
            '결재 체크리스트가 완료되지 않았습니다. 다음 항목을 확인 후 다시 시도하세요: '
            . implode(' / ', $missing),
            400
        );
    }

    // CAS: 세그먼트 내 모든 캠페인을 일괄 잠근 후 충돌 검사
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

        // VVIP→Active 같은 날 충돌 차단
        $my_seg    = DB::one('SELECT suppresses_segment_ids FROM segments WHERE id=?', [$c['segment_id']]);
        $targets   = Suppression::decode($my_seg['suppresses_segment_ids'] ?? null);
        $send_date = Suppression::extractSendDate((string)($c['send_time'] ?? ''));
        $blocking  = Suppression::findBlockingActiveCampaign($send_date, $targets);
        if ($blocking) {
            $db->rollBack();
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode([
                'success'       => false,
                'error'         => "같은 날({$send_date}) 우선순위 낮은 세그먼트 \"{$blocking['segment_name']}\"의 캠페인 \"{$blocking['name']}\"이 이미 예약 진행 중입니다. " .
                                   'Active 캠페인을 취소한 뒤 VVIP를 먼저 예약하세요.',
                'conflict_id'   => $blocking['id'],
                'conflict_name' => $blocking['name'],
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

    // 자동 검증 — Marketo 에셋명·토큰 4종을 API로 확인
    $force_verify = !empty($approve_body['force_verify']) && $approve_body['force_verify'] === true;

    $verification = ['ok' => true, 'warnings' => []];
    $seg = DB::one('SELECT marketo_program_id FROM segments WHERE id=?', [$c['segment_id']]);
    $program_id = (int)($seg['marketo_program_id'] ?? 0);
    if ($program_id > 0) {
        $verification = MarketoAPI::verifyAssetAndTokens(
            $program_id,
            (string)($c['asset_name'] ?? ''),
            build_campaign_tokens($c)
        );
        if (!empty($verification['warnings'])) {
            add_log($id, 'auto_verify',
                $verification['ok'] ? 'done' : 'error',
                ($verification['ok'] ? '자동 검증 참고: ' : '자동 검증 불일치: ')
                . implode(' | ', $verification['warnings']));
        } else {
            add_log($id, 'auto_verify', 'done', '에셋·토큰 자동 검증 통과');
        }
    } else {
        $verification['warnings'][] = 'Program ID 미설정 — 자동 검증 생략';
        add_log($id, 'auto_verify', 'done', 'Program ID 미설정 — 자동 검증 생략');
    }

    // 불일치 감지 + 강제 진행 미승인 → 스케줄링 차단, awaiting_approval 복귀
    if ($verification['ok'] === false && !$force_verify) {
        DB::exec(
            'UPDATE campaigns SET status=?, updated_at=? WHERE id=?',
            ['awaiting_approval', now_str(), $id]
        );
        record_status_transition((string)$id, 'scheduling', 'awaiting_approval', 'system',
            '자동 검증 불일치로 스케줄링 차단');
        http_response_code(409);
        header('Content-Type: application/json');
        echo json_encode([
            'success'      => false,
            'error'        => '에셋·토큰 자동 검증에서 불일치가 발견되었습니다. 확인 후 강제 진행하거나 캠페인을 수정하세요.',
            'verification' => $verification,
            'requires_force_verify' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($force_verify && $verification['ok'] === false) {
        add_log($id, 'auto_verify', 'done',
            '운영자가 검증 불일치를 확인하고 강제 진행 승인');
    }

    $logs = [];
    try {
        run_campaign_schedule($c, function(string $step, string $status, string $msg) use ($id, &$logs) {
            add_log($id, $step, $status, $msg);
            $logs[] = "[$step] $msg";
        });
        json_ok(['scheduled' => true, 'log' => $logs, 'verification' => $verification]);
    } catch (CampaignNeedsReviewException $e) {
        add_log($id, 'error', 'error', $e->getMessage());
        json_err($e->getMessage(), 500);
    } catch (Throwable $e) {
        set_campaign_status($id, 'failed', $e->getMessage());
        add_log($id, 'error', 'error', $e->getMessage());
        json_err($e->getMessage(), 500);
    }
}

function handle_cancel(string $id): void
{
    $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
    if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
    if ($c['status'] !== 'scheduled') json_err('예약된 캠페인만 취소할 수 있습니다.', 400);
    if (empty($c['marketo_email_program_id'])) {
        json_err(
            'Marketo Program/Campaign ID가 비어있어 자동 취소 불가. ' .
            'Marketo UI에서 직접 unapprove (EP) 또는 schedule 제거 (SC) 후, 본 캠페인을 삭제하거나 재시작하세요.',
            400
        );
    }

    // H-3 — cancel 시점에 *이미 sent 박제된 행* 이 있는지 확인
    $sent_row = DB::one(
        "SELECT COUNT(*) AS cnt FROM lead_send_history WHERE campaign_id=? AND state='sent'",
        [$id]
    );
    $sent_n = (int)($sent_row['cnt'] ?? 0);
    if ($sent_n > 0) {
        $cancel_body  = parse_json_body();
        $acknowledged = !empty($cancel_body['acknowledge_sent']) && $cancel_body['acknowledge_sent'] === true;
        if (!$acknowledged) {
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode([
                'success'              => false,
                'error'                => "이미 {$sent_n}명이 본 캠페인 이메일을 받았습니다. cancel 은 *남은 발송* 만 중지합니다. 진행하려면 acknowledge_sent=true 로 다시 요청하세요.",
                'already_sent_count'   => $sent_n,
                'requires_acknowledgement' => true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // SEV1 follow-up — send_mode 분기
    $send_mode = defined('MARKETO_SEND_MODE') ? MARKETO_SEND_MODE : 'smart_campaign';
    $marketo_id = (int)$c['marketo_email_program_id'];
    $note      = '';
    if ($send_mode === 'smart_campaign') {
        try {
            MarketoAPI::rescheduleSmartCampaignFarFuture($marketo_id);
            $note = '운영자 예약 취소 (Smart Campaign — runAt 을 +2년 후로 reschedule 하여 발송 중지. UI 수동 schedule 제거 권장)';
        } catch (Throwable $e) {
            json_err(
                'Marketo Smart Campaign reschedule 실패 — 발송이 중지되지 않았을 수 있습니다. ' .
                'Marketo UI 에서 즉시 schedule 을 직접 제거하세요. (오류: ' . $e->getMessage() . ')',
                502
            );
        }
    } else {
        MarketoAPI::unapproveEmailProgram($marketo_id);
        $note = '운영자 예약 취소 (EP unapprove)';
    }

    DB::exec('UPDATE campaigns SET status=?, marketo_email_program_id=NULL, updated_at=? WHERE id=?',
        ['awaiting_approval', now_str(), $id]);
    Suppression::clearForCampaign((string)$id);
    SendCap::clearForCampaign((string)$id);
    record_status_transition((string)$id, 'scheduled', 'awaiting_approval', 'user', $note);

    $payload = ['cancelled' => true];
    if ($send_mode === 'smart_campaign') {
        $payload['marketo_action'] = 'rescheduled_far_future';
        $payload['manual_action_required'] =
            'Marketo 발송은 +2년 후로 reschedule 되어 *발송 중지* 되었습니다. ' .
            '깔끔한 정리를 위해 Marketo UI 에서 해당 Smart Campaign 의 schedule 자체를 직접 제거 권장.';
    }
    json_ok($payload);
}

function handle_duplicate(string $id): void
{
    $src = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
    if (!$src) json_err('캠페인을 찾을 수 없습니다.', 404);

    $raw_st  = $src['send_time'] ?? '';
    $time_hm = strlen($raw_st) > 5 ? date('H:i', strtotime($raw_st)) : ($raw_st ?: '10:00');
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
