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
// 데이터 모델: sql/migrations/content_presets.sql 참조 (Sprint 2 ASSET 선반영).
declare(strict_types=1);
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/Presets.php';

api_handle(
    function (string $method, ?string $id, ?string $action, array $params): void {
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
            http_response_code(204);
            exit;
        }

        else {
            json_err('Not Found', 404);
        }
    },
    [
        'error_handler' => function (Throwable $e): void {
            // validate_preset_input의 입력 검증 실패 → 400
            if ($e instanceof InvalidArgumentException) {
                json_err($e->getMessage(), 400);
            }
            json_err($e->getMessage(), 500);
        },
    ]
);
