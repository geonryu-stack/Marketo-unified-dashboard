<?php
// api/content-presets.php
//
// Sprint 3 ASSET — 콘텐츠 프리셋 v2 (DB 기반 CRUD).
//
// 라우트(INFRA zone 등록):
//   GET    /api/content-presets       → 전체 프리셋 목록
//   POST   /api/content-presets       → 신규 생성 (label/emoji/title/preheader)
//   DELETE /api/content-presets/{id}  → 삭제 (204)
//
// 본 sprint 이전에는 assets/js/campaign.js의 CONTENT_PRESETS 상수가 단일 출처였다.
// v2부터는 본 endpoint가 우선이며, JS 상수는 네트워크 실패 시 fallback으로만 사용된다.
//
// 데이터 모델: sql/migrations/content_presets.sql 참조 (Sprint 2 ASSET 선반영).
//   id VARCHAR(36) PK, label, emoji, title_template, preheader_template, created_at
declare(strict_types=1);
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/Presets.php';

$method = $_SERVER['REQUEST_METHOD'];
$params = $GLOBALS['route_params'] ?? [];
$id     = $params['id'] ?? null;

try {
    // GET /api/content-presets — 목록
    if ($method === 'GET' && !$id) {
        $rows = DB::all(
            'SELECT id, label, emoji, title_template, preheader_template, created_at
             FROM content_presets
             ORDER BY created_at ASC, label ASC'
        );
        json_ok(['presets' => $rows]);
    }

    // POST /api/content-presets — 신규 생성
    elseif ($method === 'POST' && !$id) {
        $body = parse_json_body();

        // validate_preset_input은 input validation 순수 함수 (단위테스트 가능)
        $clean = validate_preset_input($body);

        $new_id = new_uuid();
        DB::exec(
            'INSERT INTO content_presets (id, label, emoji, title_template, preheader_template, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $new_id,
                $clean['label'],
                $clean['emoji'],
                $clean['title_template'],
                $clean['preheader_template'],
                now_str(),
            ]
        );
        $row = DB::one(
            'SELECT id, label, emoji, title_template, preheader_template, created_at
             FROM content_presets WHERE id=?',
            [$new_id]
        );
        json_ok($row);
    }

    // DELETE /api/content-presets/{id}
    elseif ($method === 'DELETE' && $id) {
        $existing = DB::one('SELECT id FROM content_presets WHERE id=?', [$id]);
        if (!$existing) {
            json_err('프리셋을 찾을 수 없습니다.', 404);
        }
        DB::exec('DELETE FROM content_presets WHERE id=?', [$id]);
        // 204 No Content
        http_response_code(204);
        exit;
    }

    else {
        json_err('Not Found', 404);
    }
} catch (InvalidArgumentException $e) {
    // validate_preset_input의 입력 검증 실패 → 400
    json_err($e->getMessage(), 400);
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}
