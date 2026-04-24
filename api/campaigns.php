<?php
// api/campaigns.php
declare(strict_types=1);
require_once __DIR__ . '/../src/MarketoAPI.php';
require_once __DIR__ . '/../src/InternalDB.php';

$method = $_SERVER['REQUEST_METHOD'];
$params = $GLOBALS['route_params'] ?? [];
$id     = $params['id'] ?? null;
$action = $params['action'] ?? null;

// ── 승인/거절 링크 (이메일에서 GET으로 클릭) ─────────────────
if ($id && $action === 'approve-via-link' && $method === 'GET') {
    $token      = $_GET['token'] ?? '';
    $expires_at = (int)($_GET['expires'] ?? 0);
    if (!verify_approval_token($token, 'approve', $id, $expires_at)) {
        echo '<p>링크가 만료되었거나 유효하지 않습니다.</p>'; exit;
    }
    $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
    if (!$c || $c['status'] !== 'awaiting_approval') {
        echo '<p>승인할 수 없는 상태입니다: ' . htmlspecialchars($c['status'] ?? '?') . '</p>'; exit;
    }
    DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['confirmed', now_str(), $id]);
    echo '<p>✅ 캠페인이 승인되었습니다. Phase 2 예약이 진행됩니다.</p>'; exit;
}

if ($id && $action === 'reject-via-link' && $method === 'GET') {
    $token      = $_GET['token'] ?? '';
    $expires_at = (int)($_GET['expires'] ?? 0);
    if (!verify_approval_token($token, 'reject', $id, $expires_at)) {
        echo '<p>링크가 만료되었거나 유효하지 않습니다.</p>'; exit;
    }
    DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['failed', now_str(), $id]);
    echo '<p>❌ 캠페인이 거절되었습니다.</p>'; exit;
}

try {
    // GET /api/campaigns — 목록
    if ($method === 'GET' && !$id) {
        $rows = DB::all('SELECT * FROM campaigns ORDER BY created_at DESC');
        json_ok($rows);
    }

    // GET /api/campaigns/{id}
    elseif ($method === 'GET' && $id && !$action) {
        $row = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$row) json_err('캠페인을 찾을 수 없습니다.', 404);
        json_ok($row);
    }

    // GET /api/campaigns/{id}/logs
    elseif ($method === 'GET' && $id && $action === 'logs') {
        $logs = DB::all('SELECT * FROM job_logs WHERE campaign_id=? ORDER BY created_at ASC', [$id]);
        json_ok($logs);
    }

    // POST /api/campaigns — 생성
    elseif ($method === 'POST' && !$id) {
        $body   = parse_json_body();
        $now    = now_str();
        $new_id = new_uuid();
        $seg = DB::one('SELECT name FROM segments WHERE id=?', [$body['segment_id'] ?? '']);
        DB::exec(
            'INSERT INTO campaigns
             (id, name, segment_id, segment_name, asset_name, reward_url,
              scheduled_at, send_time, status, lead_count, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,\'draft\',0,?,?)',
            [
                $new_id,
                $body['name'] ?? '',
                $body['segment_id'] ?? '',
                $seg['name'] ?? '',
                $body['asset_name'] ?? '',
                $body['reward_url'] ?? '',
                $body['scheduled_at'] ?? $now,
                $body['send_time'] ?? '10:00',
                $now, $now,
            ]
        );
        // marketo_cloned_email_id 별도 저장
        if (!empty($body['marketo_cloned_email_id'])) {
            DB::exec('UPDATE campaigns SET marketo_cloned_email_id=? WHERE id=?',
                [(string)$body['marketo_cloned_email_id'], $new_id]);
        }
        json_ok(DB::one('SELECT * FROM campaigns WHERE id=?', [$new_id]));
    }

    // POST /api/campaigns/{id}/confirm
    elseif ($method === 'POST' && $id && $action === 'confirm') {
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=? AND status=?',
            ['confirmed', now_str(), $id, 'draft']);
        json_ok(DB::one('SELECT * FROM campaigns WHERE id=?', [$id]));
    }

    // POST /api/campaigns/{id}/reset-to-draft
    elseif ($method === 'POST' && $id && $action === 'reset-to-draft') {
        $c = DB::one('SELECT status FROM campaigns WHERE id=?', [$id]);
        if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
        if ($c['status'] === 'scheduled') json_err('예약된 캠페인은 먼저 취소하세요.', 400);
        DB::exec('UPDATE campaigns SET status=?, error_message=NULL, marketo_email_program_id=NULL, updated_at=? WHERE id=?',
            ['draft', now_str(), $id]);
        json_ok(DB::one('SELECT * FROM campaigns WHERE id=?', [$id]));
    }

    // POST /api/campaigns/{id}/cancel
    elseif ($method === 'POST' && $id && $action === 'cancel') {
        $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
        if ($c['status'] !== 'scheduled') json_err('예약된 캠페인만 취소할 수 있습니다.', 400);
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['cancelling', now_str(), $id]);
        if ($c['marketo_email_program_id']) {
            MarketoAPI::unapproveEmailProgram((int)$c['marketo_email_program_id']);
        }
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['draft', now_str(), $id]);
        json_ok(['cancelled' => true]);
    }

    // POST /api/campaigns/{id}/run — Phase 1 실행
    elseif ($method === 'POST' && $id && $action === 'run') {
        run_campaign_phase1($id);
    }

    // POST /api/campaigns/{id}/approve — Phase 2 실행
    elseif ($method === 'POST' && $id && $action === 'approve') {
        run_campaign_phase2($id);
    }

    // POST /api/campaigns/{id}/reject
    elseif ($method === 'POST' && $id && $action === 'reject') {
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['failed', now_str(), $id]);
        json_ok(null);
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

// ── Phase 1 ────────────────────────────────────────────────────

function run_campaign_phase1(string $id): void
{
    $db = DB::get();

    // CAS: extracting으로 전환 (MySQL SELECT FOR UPDATE 기반 동시 실행 방지)
    $db->beginTransaction();
    try {
        $c = DB::one('SELECT * FROM campaigns WHERE id=? FOR UPDATE', [$id]);
        if (!$c) { $db->rollBack(); json_err('캠페인을 찾을 수 없습니다.', 404); }

        // 같은 세그먼트의 진행 중 캠페인 확인
        $sibling = DB::one(
            'SELECT id, name, status FROM campaigns
             WHERE segment_id=? AND id!=?
             AND status IN (\'extracting\',\'uploading\',\'preparing\',\'awaiting_approval\',\'scheduling\',\'scheduled\',\'cancelling\')',
            [$c['segment_id'], $id]
        );
        if ($sibling) {
            $db->rollBack();
            json_err("동시 실행 차단: \"{$sibling['name']}\" 캠페인이 진행 중입니다.", 409);
        }

        $allowed = ['draft', 'confirmed', 'failed'];
        if (!in_array($c['status'], $allowed)) {
            $db->rollBack();
            json_err("실행 불가: 현재 상태 ({$c['status']})", 409);
        }

        DB::exec('UPDATE campaigns SET status=?, error_message=NULL, updated_at=? WHERE id=?',
            ['extracting', now_str(), $id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $c   = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
    $seg = DB::one('SELECT * FROM segments WHERE id=?', [$c['segment_id']]);

    try {
        add_log($id, 'extract', 'running', '사내 DB 대상자 추출 시작');

        // Step 1: 대상자 추출
        $bypass = array_filter(array_map('trim', explode(',', getenv('INTERNAL_DB_BYPASS_LEADS') ?: '')));
        if (!empty($bypass)) {
            $emails = $bypass;
            add_log($id, 'extract', 'done', '[우회 모드] ' . count($emails) . '명');
        } else {
            $filters = json_decode($seg['filters'], true) ?? [];
            ['sql' => $where, 'params' => $params] = build_where_clause($filters, get_field_defs());
            $table   = INTERNAL_DB_TABLE;
            $email_f = INTERNAL_DB_EMAIL_FIELD;
            $sql     = "SELECT `$email_f` AS email FROM `$table` WHERE $where";
            assert_readonly($sql);
            $rows   = InternalDB::query($sql, $params);
            $emails = array_values(array_filter(array_column($rows, 'email')));
            add_log($id, 'extract', 'done', '추출 완료: ' . count($emails) . '명');
        }

        DB::exec('UPDATE campaigns SET lead_count=?, updated_at=? WHERE id=?', [count($emails), now_str(), $id]);

        // Step 2: Marketo 리드 업서트
        set_campaign_status($id, 'uploading');
        add_log($id, 'upsert_leads', 'running', 'Marketo 리드 업서트 시작');
        $lead_ids = MarketoAPI::upsertLeads($emails);
        add_log($id, 'upsert_leads', 'done', count($lead_ids) . '명 업서트 완료');

        // Step 3: Static List 갱신
        $list_id = (int)$seg['marketo_audience_list_id'];
        add_log($id, 'list_refresh', 'running', "Static List($list_id) 갱신 시작");
        $existing_ids = MarketoAPI::getListLeadIds($list_id);
        if (!empty($existing_ids)) {
            MarketoAPI::removeLeadsFromList($list_id, $existing_ids);
            add_log($id, 'list_refresh', 'running', '기존 멤버 ' . count($existing_ids) . '명 제거');
        }
        MarketoAPI::addLeadsToList($list_id, $lead_ids);
        DB::exec('UPDATE campaigns SET marketo_list_id=?, marketo_list_name=?, updated_at=? WHERE id=?',
            [(string)$list_id, "Audience List $list_id", now_str(), $id]);
        add_log($id, 'list_refresh', 'done', '리스트 갱신 완료: ' . count($lead_ids) . '명 추가');

        // Step 3.5: My Token 주입
        $ep_id = (int)($seg['marketo_email_program_id'] ?? 0);
        if ($ep_id) {
            add_log($id, 'set_ep_tokens', 'running', "My Token EP($ep_id)에 주입 중");
            try {
                $tokens = MarketoAPI::buildEpTokenPayload($c);
                if (!empty($tokens)) {
                    MarketoAPI::setProgramMyTokens($ep_id, $tokens);
                    add_log($id, 'set_ep_tokens', 'done', count($tokens) . '개 토큰 설정 완료');
                } else {
                    add_log($id, 'set_ep_tokens', 'done', '주입할 토큰 없음 (건너뜀)');
                }
            } catch (Throwable $te) {
                add_log($id, 'set_ep_tokens', 'error', 'My Token 설정 실패: ' . $te->getMessage());
            }
        }

        // Step 4: 테스트 메일 발송
        set_campaign_status($id, 'preparing');
        $test_emails = array_values(array_filter(array_map('trim', explode(',', SEND_TEST_EMAIL_TO))));
        if (empty($test_emails)) throw new RuntimeException('SEND_TEST_EMAIL_TO 환경 설정 누락');

        $email_asset_id = (int)($c['marketo_cloned_email_id'] ?? 0);
        if (!$email_asset_id) throw new RuntimeException('Marketo Email ID가 설정되지 않았습니다.');

        add_log($id, 'send_test_email', 'running', '테스트 메일 발송: ' . implode(', ', $test_emails));
        foreach ($test_emails as $addr) {
            MarketoAPI::sendSampleEmail($email_asset_id, $addr);
        }
        add_log($id, 'send_test_email', 'done', '테스트 메일 발송 완료');

        // 승인 이메일 발송
        $expires_at  = time() + 72 * 3600;
        $approve_url = APP_URL . '/campaigns/' . $id . '/approve-via-link?token='
                     . generate_approval_token('approve', $id, $expires_at) . '&expires=' . $expires_at;
        $reject_url  = APP_URL . '/campaigns/' . $id . '/reject-via-link?token='
                     . generate_approval_token('reject', $id, $expires_at) . '&expires=' . $expires_at;
        $fresh_c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        try { send_approval_email($fresh_c, $approve_url, $reject_url); }
        catch (Throwable $me) { add_log($id, 'send_approval_email', 'error', $me->getMessage()); }

        set_campaign_status($id, 'awaiting_approval');
        json_ok(['status' => 'awaiting_approval', 'lead_count' => count($emails)]);

    } catch (Throwable $e) {
        set_campaign_status($id, 'failed', $e->getMessage());
        add_log($id, 'error', 'error', $e->getMessage());
        json_err($e->getMessage(), 500);
    }
}

// ── Phase 2 ────────────────────────────────────────────────────

function run_campaign_phase2(string $id): void
{
    $c   = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
    $seg = DB::one('SELECT * FROM segments WHERE id=?', [$c['segment_id'] ?? '']);
    if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
    if ($c['status'] !== 'awaiting_approval') json_err('승인 대기 상태가 아닙니다.', 400);

    try {
        set_campaign_status($id, 'scheduling');
        add_log($id, 'schedule_ep', 'running', 'Email Program 예약 설정 중');

        $ep_id   = (int)($seg['marketo_email_program_id'] ?? 0);
        if (!$ep_id) throw new RuntimeException('세그먼트에 Email Program ID가 설정되지 않았습니다.');

        $send_dt = $c['send_time']
            ? date('Y-m-d', strtotime($c['scheduled_at'])) . 'T' . $c['send_time'] . ':00+0000'
            : $c['scheduled_at'];

        MarketoAPI::scheduleEmailProgram($ep_id, $send_dt);
        DB::exec('UPDATE campaigns SET marketo_email_program_id=?, updated_at=? WHERE id=?',
            [(string)$ep_id, now_str(), $id]);
        add_log($id, 'schedule_ep', 'done', "Email Program($ep_id) 예약 완료: $send_dt");
        set_campaign_status($id, 'scheduled');
        json_ok(['status' => 'scheduled']);

    } catch (Throwable $e) {
        set_campaign_status($id, 'failed', $e->getMessage());
        add_log($id, 'error', 'error', $e->getMessage());
        json_err($e->getMessage(), 500);
    }
}

// ── 공통 헬퍼 ──────────────────────────────────────────────────

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
