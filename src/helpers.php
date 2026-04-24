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

// ── 세그먼트 필드 정의 (FIELD_DEFS 이식) ─────────────────────

function get_field_defs(): array
{
    return [
        ['field' => 'email',               'label' => '이메일',                  'type' => 'text'],
        ['field' => 'user_id',             'label' => '사용자 ID',               'type' => 'text'],
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
        ['field' => 'is_active',           'label' => '활성 상태',               'type' => 'boolean'],
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

// ── 승인 토큰 (HMAC-SHA256) ───────────────────────────────────

function generate_approval_token(string $action, string $campaign_id, int $expires_at): string
{
    $payload = "$action:$campaign_id:$expires_at";
    return hash_hmac('sha256', $payload, APPROVAL_SECRET);
}

function verify_approval_token(string $token, string $action, string $campaign_id, int $expires_at): bool
{
    if (time() > $expires_at) return false;
    $expected = generate_approval_token($action, $campaign_id, $expires_at);
    return hash_equals($expected, $token);
}

// ── 캠페인 상태 한국어 레이블 ─────────────────────────────────

function status_label(string $status): string
{
    return [
        'draft'             => '초안',
        'confirmed'         => '확인 완료',
        'extracting'        => 'DB 추출 중',
        'uploading'         => '업로드 중',
        'preparing'         => '테스트 발송 중',
        'awaiting_approval' => '승인 대기',
        'scheduling'        => '예약 설정 중',
        'scheduled'         => '예약 완료',
        'cancelling'        => '예약 취소 중',
        'sent'              => '발송 완료',
        'failed'            => '실패',
    ][$status] ?? $status;
}

function status_badge_class(string $status): string
{
    return [
        'draft'             => 'secondary',
        'confirmed'         => 'primary',
        'extracting'        => 'warning',
        'uploading'         => 'warning',
        'preparing'         => 'warning',
        'awaiting_approval' => 'info',
        'scheduling'        => 'primary',
        'scheduled'         => 'success',
        'cancelling'        => 'secondary',
        'sent'              => 'success',
        'failed'            => 'danger',
    ][$status] ?? 'secondary';
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

// ── 이메일 발송 (nodemailer 대체 — PHP mail / SMTP) ──────────

function send_approval_email(array $campaign, string $approve_url, string $reject_url): void
{
    $test_emails = array_filter(array_map('trim', explode(',', SEND_TEST_EMAIL_TO)));
    if (empty($test_emails)) return;

    $subject = "[Marketo Automation] 캠페인 승인 요청: {$campaign['name']}";
    $body = "캠페인: {$campaign['name']}\n"
          . "상태: " . status_label($campaign['status']) . "\n\n"
          . "✅ 승인: $approve_url\n\n"
          . "❌ 거절: $reject_url\n";

    $headers = "From: no-reply@marketo-automation\r\nContent-Type: text/plain; charset=UTF-8";

    foreach ($test_emails as $email) {
        @mail($email, $subject, $body, $headers);
    }
}
