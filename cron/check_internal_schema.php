<?php
// cron/check_internal_schema.php
//
// Sprint 3 DB (④) — 사내 DB 스키마 드리프트 자동검출.
//
// 사내 DB(InternalDB)는 우리 앱이 통제하지 않는 외부 시스템.
// 누군가 컬럼을 추가/삭제/이름변경하면 다음 추출에서 build_where_clause()가
// 알 수 없는 필드로 throw하거나(상위 안전망), 더 나쁜 경우 의도와 다른 모수를
// 조용히 반환할 수 있다. 본 cron은 운영자 인지 지연을 막기 위한 조기 경보.
//
// 동작:
//   1) INFORMATION_SCHEMA.COLUMNS 조회 (사내 DB, SELECT only — INV-01 안전).
//   2) get_field_defs()의 expected 필드명 목록과 비교
//      (단, hidden=true는 시스템 필드여도 사내 컬럼이라 비교에 포함; sql_expr 가진 가상
//       필드는 실제 컬럼이 아니므로 expected에서 제외).
//   3) 누락된 expected 필드(=사내 DB에 없음) + 신규 미정의 필드(=사내 DB에만 있음)를 산출.
//   4) 차이가 있으면 job_log() + Notifier::slack(level=warn).
//   5) 차이 없으면 stdout 1줄 "OK".
//
// 안전:
//   - INFORMATION_SCHEMA 조회는 SELECT — InternalDB::query()의 assert_readonly 통과.
//   - 본 cron 실패는 본업 발송을 막지 않는다 (별도 cron 라인).
//
// 실행 빈도 추천: 매일 1회 (예: 매일 09:00) — 컬럼 변경은 흔치 않음.

declare(strict_types=1);

// api/internal-db.php (웹 진입점) 가 본 파일을 require_once 할 때는 이미
// config/helpers/InternalDB가 로드되어 있고 RUNNING_AS_CLI 도 정의되지 않은 상태다.
// 그 외(직접 CLI 실행) 환경에서만 부트스트랩을 수행한다.
if (!defined('RUNNING_AS_CLI') && !defined('INTERNAL_SCHEMA_CHECK_NO_MAIN')) {
    define('RUNNING_AS_CLI', true);
    chdir(dirname(__DIR__));
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../src/DB.php';
    require_once __DIR__ . '/../src/InternalDB.php';
    require_once __DIR__ . '/../src/helpers.php';
    require_once __DIR__ . '/../src/Notifier.php';
}

/**
 * 사내 DB의 실제 컬럼 목록을 가져온다.
 * @return array<string,string>  ['column_name' => 'data_type', ...]
 */
function _fetch_internal_columns(): array
{
    $sql = "SELECT COLUMN_NAME, DATA_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?";
    $rows = InternalDB::query($sql, [INTERNAL_DB_NAME, INTERNAL_DB_TABLE]);
    $out  = [];
    foreach ($rows as $r) {
        $name = (string)($r['COLUMN_NAME'] ?? $r['column_name'] ?? '');
        $type = (string)($r['DATA_TYPE']   ?? $r['data_type']   ?? '');
        if ($name === '') continue;
        $out[$name] = $type;
    }
    return $out;
}

/**
 * 우리가 기대하는 사내 컬럼 목록 — get_field_defs()에서 sql_expr 가상 필드를 뺀 잔여.
 * (sql_expr는 컬럼이 아니라 계산식이라 INFORMATION_SCHEMA에 존재하지 않는다.)
 * @return string[]
 */
function _expected_internal_columns(): array
{
    $expected = [];
    foreach (get_field_defs() as $def) {
        if (isset($def['sql_expr'])) continue;     // 가상 필드 제외
        if (empty($def['field']))    continue;
        $expected[] = (string)$def['field'];
    }
    return array_values(array_unique($expected));
}

/**
 * 비교 결과를 만든다.
 * @param array<string,string> $actual_cols  실제 사내 DB 컬럼 (이름=>타입)
 * @return array{missing_in_db:string[], unknown_in_db:string[]}
 *   missing_in_db   : 우리가 기대했는데 사내 DB에 없는 컬럼 (위험: 필터가 곧 깨짐)
 *   unknown_in_db   : 사내 DB엔 있는데 우리가 모르는 컬럼 (참고: 새 필드 가용성)
 */
function compare_internal_schema(array $actual_cols, array $expected): array
{
    $actual_names = array_keys($actual_cols);

    $missing = array_values(array_diff($expected, $actual_names));
    $unknown = array_values(array_diff($actual_names, $expected));

    return [
        'missing_in_db' => $missing,
        'unknown_in_db' => $unknown,
    ];
}

// ── cron 메인 ────────────────────────────────────────────────────
// 본 파일을 require_once 한 (api/internal-db.php) 환경에서는 main을 실행하지 않는다.
if (!defined('INTERNAL_SCHEMA_CHECK_NO_MAIN')) {
    try {
        job_log('사내 DB 스키마 드리프트 검사 시작');

        $actual   = _fetch_internal_columns();
        $expected = _expected_internal_columns();
        $diff     = compare_internal_schema($actual, $expected);

        if (empty($diff['missing_in_db']) && empty($diff['unknown_in_db'])) {
            echo "OK\n";
            job_log('스키마 드리프트 없음 (OK)');
            exit(0);
        }

        $msg_parts = [];
        if (!empty($diff['missing_in_db'])) {
            $msg_parts[] = '누락(앱 기대 > 사내DB 없음): ' . implode(', ', $diff['missing_in_db']);
        }
        if (!empty($diff['unknown_in_db'])) {
            $msg_parts[] = '신규(사내DB > 앱 미정의): '   . implode(', ', $diff['unknown_in_db']);
        }
        $msg = '사내 DB 스키마 드리프트 감지 — ' . implode(' | ', $msg_parts);

        job_log($msg);
        Notifier::slack($msg, 'warn');
        echo $msg . "\n";
        exit(1);
    } catch (Throwable $e) {
        // 본 cron 자체 실패 — Notifier로 알리되 본업은 막지 않음.
        $err = '스키마 검사 실패: ' . $e->getMessage();
        job_log($err);
        Notifier::slack($err, 'warn');
        echo $err . "\n";
        exit(2);
    }
}
