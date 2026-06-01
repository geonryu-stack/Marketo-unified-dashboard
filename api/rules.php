<?php
// api/rules.php — 발송 Rule 일괄 저장 (PUT only)
declare(strict_types=1);
require_once __DIR__ . '/../src/Suppression.php';

// sanitize_cap_int() は src/helpers/validation.php に統合済み (Phase 2)

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'PUT') {
    http_response_code(405);
    json_err('Method not allowed. PUT only.', 405);
}

$body = parse_json_body();

if (!isset($body['segments']) || !is_array($body['segments'])) {
    json_err('segments 배열이 필요합니다.', 400);
}

$segments = $body['segments'];
if (empty($segments)) {
    json_err('변경할 세그먼트가 없습니다.', 400);
}

try {
    $pdo = DB::get();
    $pdo->beginTransaction();

    foreach ($segments as $seg) {
        if (!isset($seg['id']) || !is_string($seg['id']) || $seg['id'] === '') {
            throw new RuntimeException('각 세그먼트에는 유효한 id가 필요합니다.');
        }

        // 존재 여부 확인
        $existing = DB::one('SELECT id FROM segments WHERE id = ?', [$seg['id']]);
        if (!$existing) {
            throw new RuntimeException('존재하지 않는 세그먼트 ID: ' . $seg['id']);
        }

        $cap_per_day  = sanitize_cap_int($seg['cap_per_day']  ?? null, 1);
        $cap_per_week = sanitize_cap_int($seg['cap_per_week'] ?? null, 7);
        $cap_priority = sanitize_cap_int($seg['cap_priority'] ?? null, 100);

        // suppresses_segment_ids 검증
        $supp_json = '[]';
        if (isset($seg['suppresses_segment_ids'])) {
            $supp_json = Suppression::sanitizeInput($seg['suppresses_segment_ids'], $seg['id']);
        }

        DB::exec(
            'UPDATE segments SET cap_per_day = ?, cap_per_week = ?, cap_priority = ?, suppresses_segment_ids = ?, updated_at = ? WHERE id = ?',
            [$cap_per_day, $cap_per_week, $cap_priority, $supp_json, now_str(), $seg['id']]
        );
    }

    $pdo->commit();

    // 갱신된 전체 목록 반환
    $all = DB::all('SELECT id, name, cap_per_day, cap_per_week, cap_priority, suppresses_segment_ids FROM segments ORDER BY cap_priority DESC, name ASC');
    json_ok($all);

} catch (Throwable $e) {
    if (DB::get()->inTransaction()) {
        DB::get()->rollBack();
    }
    json_err($e->getMessage(), 400);
}
