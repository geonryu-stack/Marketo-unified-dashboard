<?php
// api/segments.php
declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'];
$params = $GLOBALS['route_params'] ?? [];
$id     = $params['id'] ?? null;

try {
    // GET /api/segments — 목록
    if ($method === 'GET' && !$id) {
        $rows = DB::all('SELECT * FROM segments ORDER BY created_at DESC');
        json_ok($rows);
    }

    // GET /api/segments/{id}
    elseif ($method === 'GET' && $id) {
        $row = DB::one('SELECT * FROM segments WHERE id = ?', [$id]);
        if (!$row) json_err('세그먼트를 찾을 수 없습니다.', 404);
        json_ok($row);
    }

    // POST /api/segments — 생성
    elseif ($method === 'POST' && !$id) {
        $body    = parse_json_body();
        $filters = $body['filters'] ?? [];
        if (empty($filters)) {
            json_err('필터 조건이 없으면 전체 유저가 대상이 됩니다. 조건을 1개 이상 추가하세요.', 400);
        }
        $now  = now_str();
        $new_id = new_uuid();
        DB::exec(
            'INSERT INTO segments
             (id, name, description, filters,
              marketo_program_id, marketo_audience_list_id, marketo_email_program_id,
              is_recurring, send_day_of_week, recurring_send_time,
              default_email_id, default_asset_name, default_reward_url,
              default_emoji, default_send_time, default_name_prefix,
              created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $new_id,
                $body['name'] ?? '',
                $body['description'] ?? '',
                json_encode($body['filters'] ?? []),
                $body['marketo_program_id'] ?? '',
                $body['marketo_audience_list_id'] ?? '',
                $body['marketo_email_program_id'] ?? '',
                (int)($body['is_recurring'] ?? 0),
                (int)($body['send_day_of_week'] ?? 1),
                $body['recurring_send_time'] ?? '10:00',
                $body['default_email_id']    ?? '',
                $body['default_asset_name']  ?? '',
                $body['default_reward_url']  ?? '',
                $body['default_emoji']       ?? null,
                $body['default_send_time']   ?? '10:00',
                $body['default_name_prefix'] ?? '',
                $now, $now,
            ]
        );
        json_ok(DB::one('SELECT * FROM segments WHERE id = ?', [$new_id]));
    }

    // PUT /api/segments/{id} — 수정
    elseif ($method === 'PUT' && $id) {
        $existing = DB::one('SELECT * FROM segments WHERE id=?', [$id]);
        if (!$existing) json_err('세그먼트를 찾을 수 없습니다.', 404);

        $body    = parse_json_body();
        $filters = $body['filters'] ?? [];
        if (empty($filters)) {
            json_err('필터 조건이 없으면 전체 유저가 대상이 됩니다. 조건을 1개 이상 추가하세요.', 400);
        }
        $now = now_str();
        DB::exec(
            'UPDATE segments SET
             name=?, description=?, filters=?,
             marketo_program_id=?, marketo_audience_list_id=?, marketo_email_program_id=?,
             is_recurring=?, send_day_of_week=?, recurring_send_time=?,
             default_email_id=?, default_asset_name=?, default_reward_url=?,
             default_emoji=?, default_send_time=?, default_name_prefix=?,
             updated_at=?
             WHERE id=?',
            [
                $body['name']        ?? $existing['name'],
                $body['description'] ?? $existing['description'],
                json_encode($filters),
                $body['marketo_program_id']       ?? $existing['marketo_program_id'],
                $body['marketo_audience_list_id'] ?? $existing['marketo_audience_list_id'],
                $body['marketo_email_program_id'] ?? $existing['marketo_email_program_id'],
                isset($body['is_recurring'])      ? (int)$body['is_recurring']      : (int)$existing['is_recurring'],
                isset($body['send_day_of_week'])  ? (int)$body['send_day_of_week']  : (int)$existing['send_day_of_week'],
                $body['recurring_send_time'] ?? $existing['recurring_send_time'],
                $body['default_email_id']    ?? $existing['default_email_id'],
                $body['default_asset_name']  ?? $existing['default_asset_name'],
                $body['default_reward_url']  ?? $existing['default_reward_url'],
                $body['default_emoji']       ?? $existing['default_emoji'],
                $body['default_send_time']   ?? $existing['default_send_time'],
                $body['default_name_prefix'] ?? $existing['default_name_prefix'],
                $now, $id,
            ]
        );
        json_ok(DB::one('SELECT * FROM segments WHERE id = ?', [$id]));
    }

    // DELETE /api/segments/{id}
    elseif ($method === 'DELETE' && $id) {
        DB::exec('DELETE FROM segments WHERE id = ?', [$id]);
        json_ok(null);
    }

    else {
        json_err('Not Found', 404);
    }
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}
