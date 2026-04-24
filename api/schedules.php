<?php
// api/schedules.php
declare(strict_types=1);
require_once __DIR__ . '/../src/MarketoAPI.php';

$method = $_SERVER['REQUEST_METHOD'];
$params = $GLOBALS['route_params'] ?? [];
$id     = $params['id'] ?? null;
$action = $params['action'] ?? null;

try {
    // GET /api/schedules?week=YYYY-MM-DD
    if ($method === 'GET' && !$id) {
        $week = $_GET['week'] ?? date('Y-m-d');
        $dates = [];
        $monday = date('Y-m-d', strtotime('monday this week', strtotime($week)));
        for ($i = 0; $i < 7; $i++) {
            $dates[] = date('Y-m-d', strtotime("+$i days", strtotime($monday)));
        }
        $groups = DB::all('SELECT * FROM groups ORDER BY sort_order');
        $schedules = DB::all(
            'SELECT * FROM send_schedules WHERE send_date BETWEEN ? AND ?',
            [$dates[0], $dates[6]]
        );
        json_ok(['groups' => $groups, 'schedules' => $schedules, 'dates' => $dates]);
    }

    // POST /api/schedules — 생성/갱신 (upsert)
    elseif ($method === 'POST' && !$id) {
        $body = parse_json_body();
        $now  = now_str();
        $existing = DB::one('SELECT id FROM send_schedules WHERE group_id=? AND send_date=?',
            [$body['group_id'], $body['send_date']]);
        if ($existing) {
            DB::exec('UPDATE send_schedules SET marketo_email_id=?, marketo_email_name=?, send_time=?, timezone=?, updated_at=? WHERE id=?',
                [$body['marketo_email_id'], $body['marketo_email_name'] ?? '', $body['send_time'] ?? '10:00', $body['timezone'] ?? 'RTZ', $now, $existing['id']]);
            json_ok(DB::one('SELECT * FROM send_schedules WHERE id=?', [$existing['id']]));
        } else {
            $new_id = new_uuid();
            DB::exec('INSERT INTO send_schedules (id,group_id,send_date,marketo_email_id,marketo_email_name,send_time,timezone,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$new_id, $body['group_id'], $body['send_date'], $body['marketo_email_id'], $body['marketo_email_name'] ?? '', $body['send_time'] ?? '10:00', $body['timezone'] ?? 'RTZ', 'draft', $now, $now]);
            json_ok(DB::one('SELECT * FROM send_schedules WHERE id=?', [$new_id]));
        }
    }

    // POST /api/schedules/{id}/test — 테스트 메일
    elseif ($method === 'POST' && $id && $action === 'test') {
        $s = DB::one('SELECT * FROM send_schedules WHERE id=?', [$id]);
        if (!$s) json_err('스케줄을 찾을 수 없습니다.', 404);
        $test_emails = array_values(array_filter(array_map('trim', explode(',', SEND_TEST_EMAIL_TO))));
        foreach ($test_emails as $email) {
            MarketoAPI::sendSampleEmail((int)$s['marketo_email_id'], $email);
        }
        DB::exec('UPDATE send_schedules SET status=?, test_sent_at=?, updated_at=? WHERE id=?',
            ['test_sent', now_str(), now_str(), $id]);
        json_ok(['sent_to' => $test_emails]);
    }

    // POST /api/schedules/{id}/schedule — Marketo 예약
    elseif ($method === 'POST' && $id && $action === 'schedule') {
        $s = DB::one('SELECT * FROM send_schedules WHERE id=?', [$id]);
        if (!$s) json_err('스케줄을 찾을 수 없습니다.', 404);
        $g = DB::one('SELECT * FROM groups WHERE id=?', [$s['group_id']]);
        $dt = $s['send_date'] . 'T' . $s['send_time'] . ':00+0000';
        MarketoAPI::scheduleEmailProgram((int)$g['marketo_campaign_id'], $dt);
        DB::exec('UPDATE send_schedules SET status=?, scheduled_at=?, updated_at=? WHERE id=?',
            ['scheduled', now_str(), now_str(), $id]);
        json_ok(['scheduled_at' => $dt]);
    }

    // DELETE /api/schedules/{id}
    elseif ($method === 'DELETE' && $id && !$action) {
        DB::exec('DELETE FROM send_schedules WHERE id=?', [$id]);
        json_ok(null);
    }

    else {
        json_err('Not Found', 404);
    }
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}
