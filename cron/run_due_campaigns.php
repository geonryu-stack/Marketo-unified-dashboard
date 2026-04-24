<?php
// cron/run_due_campaigns.php — PHP CLI 전용 (Windows 작업 스케줄러 실행 대상)
// 실행: php C:\xampp\htdocs\marketo-automation\cron\run_due_campaigns.php
declare(strict_types=1);

define('RUNNING_AS_CLI', true);

// CLI에서 실행되므로 경로를 절대경로로 설정
chdir(dirname(__DIR__));

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/InternalDB.php';
require_once __DIR__ . '/../src/MarketoAPI.php';
require_once __DIR__ . '/../src/helpers.php';

function cron_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

cron_log('Cron 시작');

// confirmed 상태이고 scheduled_at <= NOW() 인 캠페인 조회
$due = DB::all(
    "SELECT * FROM campaigns
     WHERE status = 'confirmed' AND scheduled_at <= ?
     ORDER BY scheduled_at ASC
     LIMIT 5",
    [now_str()]
);

if (empty($due)) {
    cron_log('실행 대상 캠페인 없음');
    exit(0);
}

foreach ($due as $c) {
    cron_log("캠페인 실행 시작: {$c['name']} (ID: {$c['id']})");
    try {
        // Phase 1 로직 직접 실행
        // CAS 전환
        $db = DB::get();
        $db->beginTransaction();
        $locked = DB::one('SELECT * FROM campaigns WHERE id=? FOR UPDATE', [$c['id']]);
        if (!$locked || $locked['status'] !== 'confirmed') {
            $db->rollBack();
            cron_log("  → 건너뜀: 상태 변경됨 ({$locked['status']})");
            continue;
        }
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['extracting', now_str(), $c['id']]);
        $db->commit();

        $seg = DB::one('SELECT * FROM segments WHERE id=?', [$c['segment_id']]);

        // 대상자 추출
        $filters = json_decode($seg['filters'], true) ?? [];
        ['sql' => $where, 'params' => $params] = build_where_clause($filters, get_field_defs());
        $table   = INTERNAL_DB_TABLE;
        $email_f = INTERNAL_DB_EMAIL_FIELD;
        $sql     = "SELECT `$email_f` AS email FROM `$table` WHERE $where";
        assert_readonly($sql);
        $rows   = InternalDB::query($sql, $params);
        $emails = array_values(array_filter(array_column($rows, 'email')));
        cron_log("  추출 완료: " . count($emails) . "명");

        DB::exec('UPDATE campaigns SET lead_count=?, updated_at=? WHERE id=?', [count($emails), now_str(), $c['id']]);
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['uploading', now_str(), $c['id']]);

        // 리드 업서트
        $lead_ids = MarketoAPI::upsertLeads($emails);

        // Static List 갱신
        $list_id = (int)$seg['marketo_audience_list_id'];
        $existing = MarketoAPI::getListLeadIds($list_id);
        if (!empty($existing)) MarketoAPI::removeLeadsFromList($list_id, $existing);
        MarketoAPI::addLeadsToList($list_id, $lead_ids);

        // My Token 주입
        $ep_id = (int)($seg['marketo_email_program_id'] ?? 0);
        if ($ep_id) {
            try {
                $tokens = MarketoAPI::buildEpTokenPayload($c);
                if (!empty($tokens)) MarketoAPI::setProgramMyTokens($ep_id, array_values($tokens));
            } catch (Throwable $te) {
                cron_log("  My Token 주입 실패 (계속): " . $te->getMessage());
            }
        }

        // 테스트 메일 발송
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['preparing', now_str(), $c['id']]);
        $test_emails = array_values(array_filter(array_map('trim', explode(',', SEND_TEST_EMAIL_TO))));
        $email_asset_id = (int)($c['marketo_cloned_email_id'] ?? 0);
        if ($email_asset_id && !empty($test_emails)) {
            foreach ($test_emails as $addr) MarketoAPI::sendSampleEmail($email_asset_id, $addr);
        }

        // 승인 이메일
        $expires_at  = time() + 72 * 3600;
        $approve_url = APP_URL . '/campaigns/' . $c['id'] . '/approve-via-link?token='
                     . generate_approval_token('approve', $c['id'], $expires_at) . '&expires=' . $expires_at;
        $reject_url  = APP_URL . '/campaigns/' . $c['id'] . '/reject-via-link?token='
                     . generate_approval_token('reject', $c['id'], $expires_at)  . '&expires=' . $expires_at;
        $fresh = DB::one('SELECT * FROM campaigns WHERE id=?', [$c['id']]);
        send_approval_email($fresh, $approve_url, $reject_url);

        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['awaiting_approval', now_str(), $c['id']]);
        cron_log("  Phase 1 완료 → awaiting_approval");

    } catch (Throwable $e) {
        DB::exec('UPDATE campaigns SET status=?, error_message=?, updated_at=? WHERE id=?',
            ['failed', $e->getMessage(), now_str(), $c['id']]);
        cron_log("  오류: " . $e->getMessage());
    }
}

cron_log('Cron 완료');
