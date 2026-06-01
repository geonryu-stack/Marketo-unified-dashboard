<?php
// api/internal-db.php
declare(strict_types=1);
require_once __DIR__ . '/../src/InternalDB.php';

$action = $GLOBALS['route_params']['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// GET /api/internal-db/fields
if ($action === 'fields' && $method === 'GET') {
    json_ok(get_field_defs());
}

// POST /api/internal-db/preview
//
// Body:
//   {
//     "filters":        [...],          // 기존 필터 배열
//     "sample":         true|false,     // (옵션) true면 표본 10건 함께 반환
//     "consent_guard":  true|false      // (옵션, 기본 true) marketing_consent=1 AND is_active=1 AND <user filters>
//   }
//
// Response:
//   {
//     "count": int,
//     "consent_guard_applied": bool,
//     "sample": [ { email_masked, country, days_since_login }, ... ]   // sample=true 인 경우만
//   }
//
// INV-01: 사내 DB는 SELECT만. assert_readonly가 항상 보호.
// PII: sample 응답의 email은 mask_email_pii로 반드시 마스킹됨.
elseif ($action === 'preview' && $method === 'POST') {
    try {
        $body          = parse_json_body();
        $filters       = $body['filters'] ?? [];
        $want_sample   = !empty($body['sample']);
        // 기본 ON: 명시적으로 false가 오지 않은 한 동의/활성 가드 켬
        $consent_guard = !array_key_exists('consent_guard', $body) || !empty($body['consent_guard']);

        ['sql' => $user_where, 'params' => $params] = build_where_clause($filters, get_field_defs());

        // consent_guard: 운영자 실수로 비동의/비활성 회원이 모수에 들어가는 사고를 막는다.
        $guard_sql = $consent_guard
            ? '(`marketing_consent` = 1 AND `is_active` = 1)'
            : '1=1';

        $where = "$guard_sql AND ($user_where)";

        $table = INTERNAL_DB_TABLE;

        // 1) COUNT (가드 적용된 WHERE 사용)
        $count_sql = "SELECT COUNT(*) AS cnt FROM `$table` WHERE $where";
        assert_readonly($count_sql);
        $rows  = InternalDB::query($count_sql, $params);
        $count = (int)($rows[0]['cnt'] ?? 0);

        $resp = [
            'count'                 => $count,
            'consent_guard_applied' => $consent_guard,
        ];

        // 세그먼트 유형별 가드 힌트 (IMPROVEMENT_SPEC #1)
        $seg_type = (string)($body['segment_type'] ?? '');
        if ($seg_type !== '') {
            $resp['guard_hint'] = get_segment_type_guard_hint($seg_type);
        }

        // 2) 표본 미리보기 (옵션) — 최대 10건, PII 마스킹
        if ($want_sample) {
            $sample_sql = "
                SELECT
                    `email`,
                    `country`,
                    DATEDIFF(NOW(), `last_login_at`) AS days_since_login
                FROM `$table`
                WHERE $where
                LIMIT 10
            ";
            assert_readonly($sample_sql);
            $sample_rows = InternalDB::query($sample_sql, $params);

            $resp['sample'] = array_map(function ($r) {
                return [
                    'email_masked'     => mask_email_pii((string)($r['email'] ?? '')),
                    'country'          => $r['country'] ?? null,
                    'days_since_login' => isset($r['days_since_login']) ? (int)$r['days_since_login'] : null,
                ];
            }, $sample_rows);
        }

        json_ok($resp);
    } catch (Throwable $e) {
        json_err($e->getMessage());
    }
}

// GET /api/internal-db/schema-drift
//
// Sprint 3 DB (④) — 운영자 수동 트리거 엔드포인트.
// cron/check_internal_schema.php와 동일한 비교 로직을 동기 실행하고 JSON 반환.
//
// Response:
//   {
//     ok: bool,                  // 차이 없으면 true
//     missing_in_db: [...],      // 우리가 기대했는데 사내 DB에 없는 컬럼
//     unknown_in_db: [...],      // 사내 DB엔 있는데 우리가 모르는 컬럼
//     checked_at: 'YYYY-mm-dd HH:ii:ss'
//   }
//
// INV-01: INFORMATION_SCHEMA.COLUMNS 는 SELECT — InternalDB::query()의 assert_readonly 통과.
// 슬랙 알림은 cron 경로에서만 발사. 운영자 수동 트리거는 결과만 반환(반복 호출시 알림 폭주 방지).
elseif ($action === 'schema-drift' && $method === 'GET') {
    try {
        // main 블록 건너뛰고 함수만 사용하기 위한 가드.
        if (!defined('INTERNAL_SCHEMA_CHECK_NO_MAIN')) {
            define('INTERNAL_SCHEMA_CHECK_NO_MAIN', true);
        }
        require_once __DIR__ . '/../cron/check_internal_schema.php';

        $actual   = _fetch_internal_columns();
        $expected = _expected_internal_columns();
        $diff     = compare_internal_schema($actual, $expected);
        $ok       = empty($diff['missing_in_db']) && empty($diff['unknown_in_db']);

        json_ok([
            'ok'            => $ok,
            'missing_in_db' => $diff['missing_in_db'],
            'unknown_in_db' => $diff['unknown_in_db'],
            'checked_at'    => now_str(),
        ]);
    } catch (Throwable $e) {
        json_err($e->getMessage());
    }
}

else {
    json_err('Not Found', 404);
}
