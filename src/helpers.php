<?php
// src/helpers.php
declare(strict_types=1);

// ── HTTP 응답 ─────────────────────────────────────────────────

function json_ok(mixed $data = null): void
{
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $error, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── UUID, 날짜 ────────────────────────────────────────────────

function new_uuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function now_str(): string
{
    return (new DateTime())->format('Y-m-d H:i:s');
}

// ── 보안: SQL 읽기전용 강제 ──────────────────────────────────

function assert_readonly(string $sql): void
{
    $normalized = strtoupper(ltrim($sql));
    if (!str_starts_with($normalized, 'SELECT') && !str_starts_with($normalized, 'WITH')) {
        throw new RuntimeException('CONSTRAINT-01: 사내 DB는 SELECT 쿼리만 허용됩니다.');
    }
}

// ── PII 마스킹: 표본 미리보기 ────────────────────────────────
// 사내 DB 표본을 화면에 노출할 때 평문 이메일을 차단.
//   - 로컬파트: 앞 2자 + ***
//   - 도메인:   앞 2자 + ***. + TLD(마지막 . 이후)
// 이메일 형식 아니면 "***" 반환.
function mask_email_pii(string $email): string
{
    $email = trim($email);
    if ($email === '') return '***';

    $at = strrpos($email, '@');
    if ($at === false || $at === 0 || $at === strlen($email) - 1) {
        return '***';
    }

    $local  = substr($email, 0, $at);
    $domain = substr($email, $at + 1);

    $dot = strrpos($domain, '.');
    if ($dot === false || $dot === 0 || $dot === strlen($domain) - 1) {
        return '***';
    }

    $domain_main = substr($domain, 0, $dot);
    $tld         = substr($domain, $dot + 1);

    $local_prefix  = mb_substr($local, 0, 2, 'UTF-8');
    $domain_prefix = mb_substr($domain_main, 0, 2, 'UTF-8');

    return $local_prefix . '***@' . $domain_prefix . '***.' . $tld;
}

// ── 세그먼트 필드 정의 (FIELD_DEFS 이식) ─────────────────────

function get_field_defs(): array
{
    return [
        ['field' => 'email',               'label' => '이메일',                  'type' => 'text',    'hidden' => true],
        ['field' => 'user_id',             'label' => '사용자 ID',               'type' => 'text',    'hidden' => true],
        ['field' => 'is_active',           'label' => '활성 상태',               'type' => 'boolean', 'hidden' => true],
        ['field' => 'country',             'label' => '국가',                    'type' => 'select',
         'options' => ['KR','US','JP','TW','TH','VN','PH']],
        ['field' => 'platform',            'label' => '플랫폼',                  'type' => 'select',
         'options' => ['ios','android']],
        ['field' => 'language',            'label' => '언어',                    'type' => 'select',
         'options' => ['ko','en','ja','zh','th','vi']],
        ['field' => 'days_since_login',    'label' => '마지막 로그인 경과일 (일)', 'type' => 'number',
         'sql_expr' => 'DATEDIFF(NOW(), last_login_at)'],
        ['field' => 'days_since_register', 'label' => '가입 후 경과일 (일)',      'type' => 'number',
         'sql_expr' => 'DATEDIFF(NOW(), created_at)'],
        ['field' => 'total_purchase_count','label' => '총 결제 횟수',             'type' => 'number'],
        ['field' => 'total_purchase_amount','label' => '총 결제 금액',            'type' => 'number'],
        ['field' => 'days_since_purchase', 'label' => '마지막 결제 경과일 (일)',  'type' => 'number',
         'sql_expr' => 'DATEDIFF(NOW(), last_purchase_at)'],
        ['field' => 'user_level',          'label' => '사용자 레벨',             'type' => 'number'],
        ['field' => 'marketing_consent',   'label' => '마케팅 수신 동의',         'type' => 'boolean'],
    ];
}

// ── SQL WHERE 빌더 (buildWhereClause 이식) ───────────────────

function build_where_clause(array $filters, array $field_defs): array
{
    if (empty($filters)) {
        return ['sql' => '1=1', 'params' => []];
    }

    $def_map = array_column($field_defs, null, 'field');
    $clauses = [];
    $params  = [];

    foreach ($filters as $f) {
        if (!isset($def_map[$f['field']])) {
            throw new RuntimeException(
                "알 수 없는 필터 필드: '{$f['field']}'. 세그먼트 편집 화면에서 해당 조건을 제거하거나 유효한 필드로 교체하세요."
            );
        }
        $def = $def_map[$f['field']];
        $col = isset($def['sql_expr']) ? $def['sql_expr'] : '`' . $def['field'] . '`';
        $op  = $f['operator'];

        switch ($op) {
            case '=': case '!=': case '>': case '>=': case '<': case '<=':
                $clauses[] = "$col $op ?";
                $params[]  = cast_filter_value($f['value'], $def['type']);
                break;
            case 'IN': case 'NOT IN':
                $vals = array_values(array_filter(array_map('trim', explode(',', $f['value']))));
                if (empty($vals)) continue 2;
                $ph = implode(', ', array_fill(0, count($vals), '?'));
                $clauses[] = "$col $op ($ph)";
                foreach ($vals as $v) {
                    $params[] = cast_filter_value($v, $def['type']);
                }
                break;
            case 'LIKE':
                $clauses[] = "$col LIKE ?";
                $params[]  = '%' . $f['value'] . '%';
                break;
            case 'IS NULL':
                $clauses[] = "$col IS NULL";
                break;
            case 'IS NOT NULL':
                $clauses[] = "$col IS NOT NULL";
                break;
        }
    }

    return [
        'sql'    => empty($clauses) ? '1=1' : implode(' AND ', $clauses),
        'params' => $params,
    ];
}

function cast_filter_value(string $value, string $type): mixed
{
    if ($type === 'number') return is_numeric($value) ? $value + 0 : $value;
    if ($type === 'boolean') return ($value === 'true' || $value === '1') ? 1 : 0;
    return $value;
}


// ── 캠페인 상태 한국어 레이블 ─────────────────────────────────

function status_label(string $status): string
{
    return [
        'draft'               => '초안',
        'awaiting_approval'   => '결재 대기',
        'scheduling'          => '예약 설정 중',
        'bulk_polling'        => '대용량 업로드 중',
        'bulk_finalizing'    => 'EP 예약 진행 중',
        'scheduled'           => '예약 완료',
        'sent'                => '발송 완료',
        'needs_manual_review' => '수동 검토 필요',
        'failed'              => '실패',
        // 하위호환: migration_approval 이전 데이터 + send_schedules 상태값 표시
        'test_sent'           => '테스트 발송 완료',
    ][$status] ?? $status;
}

function status_badge_class(string $status): string
{
    return [
        'draft'               => 'secondary',
        'awaiting_approval'   => 'warning',
        'scheduling'          => 'warning',
        'bulk_polling'        => 'warning',
        'bulk_finalizing'    => 'warning',
        'scheduled'           => 'success',
        'sent'                => 'success',
        'needs_manual_review' => 'danger',
        'failed'              => 'danger',
        'test_sent'           => 'info',
    ][$status] ?? 'secondary';
}

// ── 캠페인 My Token 배열 생성 ─────────────────────────────────

function build_campaign_tokens(array $c): array
{
    return [
        // 헤더(Subject)에 삽입 → RFC 2047 MIME encoded-word
        ['name' => 'Emoji',     'value' => mime_header_value((string)($c['emoji']           ?? '')), 'type' => 'text'],
        ['name' => 'Title',     'value' => mime_header_value((string)($c['email_title']     ?? '')), 'type' => 'text'],
        // 바디(<span>)에 삽입 → HTML 엔티티
        ['name' => 'Preheader', 'value' => html_body_value((string)($c['email_preheader']  ?? '')), 'type' => 'text'],
        // URL → 그대로 (ASCII)
        ['name' => 'RewardUrl', 'value' => (string)($c['reward_url'] ?? ''),                        'type' => 'text'],
    ];
}

/**
 * Marketo 토큰 응답의 키 이름을 정규화한다.
 * Marketo API는 토큰 이름을 'my.Emoji' 또는 'Emoji' 둘 중 어느 형태로도 돌려줄 수 있다.
 * 'my.' 접두사를 안전하게 제거하여 비교 가능한 키로 만든다.
 */
function normalize_token_name(string $name): string
{
    return str_starts_with($name, 'my.') ? substr($name, 3) : $name;
}

/**
 * C-TOKEN-VERIFY: 기대 토큰 배열과 Marketo 응답을 비교해 불일치 목록을 반환한다.
 * 순수 함수(외부 호출 없음) — 단위 테스트 가능.
 *
 * @param array $expected build_campaign_tokens() 결과 형식
 * @param array $actual   MarketoAPI::getProgramTokens() 응답 형식
 * @return string[]       빈 배열이면 모두 일치. 그 외엔 사람이 읽을 수 있는 diff 메시지 목록.
 */
function diff_campaign_tokens(array $expected, array $actual): array
{
    $actual_map = [];
    foreach ($actual as $t) {
        if (!isset($t['name'])) continue;
        $key = normalize_token_name((string)$t['name']);
        $actual_map[$key] = (string)($t['value'] ?? '');
    }

    $mismatches = [];
    foreach ($expected as $t) {
        $key            = (string)($t['name']  ?? '');
        $expected_value = (string)($t['value'] ?? '');

        if (!array_key_exists($key, $actual_map)) {
            $mismatches[] = "{$key}: missing in Marketo response";
            continue;
        }
        $actual_value = $actual_map[$key];
        if ($expected_value !== $actual_value) {
            $exp_disp = str_replace('"', '\\"', $expected_value);
            $act_disp = str_replace('"', '\\"', $actual_value);
            $mismatches[] = "{$key}: expected=\"{$exp_disp}\" vs actual=\"{$act_disp}\"";
        }
    }

    return $mismatches;
}

/**
 * 이메일 헤더(Subject 등) 삽입용.
 * Marketo API가 비-ASCII를 Latin-1로 이중 인코딩하는 버그를 우회.
 * RFC 2047 Base64 encoded-word로 감싸 ASCII만 전송 → 이메일 클라이언트가 헤더에서 디코딩.
 */
function mime_header_value(string $value): string
{
    if ($value === '') return '';
    if (mb_check_encoding($value, 'ASCII')) return $value;
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/**
 * 이메일 바디(HTML) 삽입용.
 * 비-ASCII 문자를 HTML 엔티티(&#xHHHH;)로 변환 → 모두 ASCII이므로 Marketo API 버그 우회.
 * HTML 이메일 클라이언트가 바디에서 엔티티를 디코딩해 올바른 문자로 표시.
 */
function html_body_value(string $value): string
{
    if ($value === '') return '';
    $result = '';
    $len = mb_strlen($value, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $char = mb_substr($value, $i, 1, 'UTF-8');
        $ord  = mb_ord($char, 'UTF-8');
        $result .= $ord < 128 ? htmlspecialchars($char, ENT_QUOTES) : '&#x' . strtoupper(dechex($ord)) . ';';
    }
    return $result;
}

// ── send_time → Unix timestamp 변환 ──────────────────────────────

function parse_send_time(string $raw): int
{
    if (!$raw) return 0;
    return (int)strtotime(str_replace('T', ' ', $raw));
}

// ── JSON 바디 파싱 ─────────────────────────────────────────────

function parse_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_err('Invalid JSON body', 400);
    }
    return $data ?? [];
}

// ── cron / 일반 잡 로그 기록 ─────────────────────────────────────

/**
 * stdout과 job_logs 테이블에 동시 기록. cron 전용 헬퍼였던 cron_add_log/bulk_add_log/
 * poll_log를 통합. $campaign_id가 null이면 stdout만.
 *
 * Sprint 0 INFRA 확장: $run_id 옵션 추가.
 *  - 주어지면 job_logs.run_id 컬럼에 INSERT되고, stdout 라인 앞에 `[run:xxxxxxxx]`
 *    단축 prefix(앞 8자)가 붙어 동일 발송 사이클의 로그를 grep으로 묶기 쉬워진다.
 *  - null이면 기존 동작과 100% 동일 (BC 유지) — 컬럼은 NULL로 들어간다.
 */
function job_log(
    string $message,
    ?string $campaign_id = null,
    string $step = 'cron',
    string $status = 'info',
    ?string $run_id = null
): void {
    if (defined('RUNNING_AS_CLI') && RUNNING_AS_CLI) {
        $prefix = $run_id !== null ? '[run:' . substr($run_id, 0, 8) . '] ' : '';
        echo '[' . date('Y-m-d H:i:s') . '] ' . $prefix . $message . PHP_EOL;
    }
    if ($campaign_id !== null) {
        DB::exec(
            'INSERT INTO job_logs (id, campaign_id, step, status, run_id, message, created_at) VALUES (?,?,?,?,?,?,?)',
            [new_uuid(), $campaign_id, $step, $status, $run_id, $message, now_str()]
        );
    }
}

// ── DRY_RUN_MODE 헬퍼 ─────────────────────────────────────────────

/**
 * DRY_RUN_MODE 플래그 조회. config/config.php 에 DRY_RUN_MODE=true 가 정의되어 있으면
 * Marketo 부수효과 호출(POST/DELETE 등)을 no-op + 로그만으로 대체할 수 있다.
 * 본 sprint(S0)에서는 플래그/헬퍼만 도입. 실제 분기는 S1에 MKT zone 에서 적용한다.
 */
function is_dry_run(): bool
{
    return defined('DRY_RUN_MODE') && DRY_RUN_MODE === true;
}

