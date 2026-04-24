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
        $body = parse_json_body();
        $now  = now_str();
        $new_id = new_uuid();
        DB::exec(
            'INSERT INTO segments
             (id, name, description, filters,
              marketo_program_id, marketo_audience_list_id, marketo_email_program_id,
              is_recurring, send_day_of_week, recurring_send_time, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
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
                $now, $now,
            ]
        );
        json_ok(DB::one('SELECT * FROM segments WHERE id = ?', [$new_id]));
    }

    // PUT /api/segments/{id} — 수정
    elseif ($method === 'PUT' && $id) {
        $body = parse_json_body();
        $now  = now_str();
        DB::exec(
            'UPDATE segments SET
             name=?, description=?, filters=?,
             marketo_program_id=?, marketo_audience_list_id=?, marketo_email_program_id=?,
             is_recurring=?, send_day_of_week=?, recurring_send_time=?, updated_at=?
             WHERE id=?',
            [
                $body['name'] ?? '',
                $body['description'] ?? '',
                json_encode($body['filters'] ?? []),
                $body['marketo_program_id'] ?? '',
                $body['marketo_audience_list_id'] ?? '',
                $body['marketo_email_program_id'] ?? '',
                (int)($body['is_recurring'] ?? 0),
                (int)($body['send_day_of_week'] ?? 1),
                $body['recurring_send_time'] ?? '10:00',
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
