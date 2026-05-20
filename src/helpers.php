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

/**
 * C-LEAD-COUNT (CRITICS.md §2 ★★★) — segments.last_count 대비 드리프트 검사.
 *
 * Sprint 1 DB 트랙. ScheduleRunner.extract_campaign_leads()에서
 * 새 추출 카운트가 박제(UPDATE)되기 *직전*에 호출하여 이전 회차 대비 임계치를
 * 넘는 증감을 잡는다. 사내 DB 스키마 변경/필터 의미 변경으로 인한
 * "조용한 폭주(+700%)" 또는 "조용한 실종(-90%)"을 운영자에게 노출한다.
 *
 *  - last_count NULL  : 첫 추출 → 비교 불가 → null 반환 (무경고)
 *  - 변동률 ≤ threshold : null (무경고)
 *  - 변동률 > threshold : 사람이 읽을 수 있는 경고 문자열 반환
 *
 * 호출자는 이 결과를 캠페인 결재 카드/needs_manual_review 결정 분기에서 사용.
 * 본 함수는 순수 조회/계산 — 사이드이펙트 없음 (DB UPDATE는 호출자 책임).
 *
 * @param string $segment_id     segments.id
 * @param int    $current_count  새로 추출된 대상자 수
 * @param float  $threshold      0..1 비율. 기본 0.5 (=50% 편차).
 * @return string|null           null=정상/첫회차, 문자열=운영자 표시용 경고 메시지
 */
function check_lead_count_drift(string $segment_id, int $current_count, float $threshold = 0.5): ?string
{
    $row = DB::one('SELECT last_count FROM segments WHERE id=?', [$segment_id]);
    if ($row === null || !isset($row['last_count']) || $row['last_count'] === null) {
        return null; // 첫 회차 — 비교 기준 없음
    }

    $last = (int)$row['last_count'];
    // 분모 0 회피: max(last, 1). last=0인 경우(가능성 낮음) current>0이면 무한대 → 임계 항상 초과.
    $denom  = max($last, 1);
    $delta  = $current_count - $last;
    $ratio  = abs($delta) / $denom;

    if ($ratio <= $threshold) {
        return null;
    }

    $sign      = $delta >= 0 ? '+' : '-';
    $pct       = (int)round(abs($delta) / $denom * 100);
    $thresh_pct = (int)round($threshold * 100);
    return sprintf(
        '이전 추출 %s명 → 현재 %s명 (%s%d%%) — 드리프트 임계치(%d%%) 초과',
        number_format($last),
        number_format($current_count),
        $sign,
        $pct,
        $thresh_pct
    );
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
//
// Sprint 3 DB (③) — 필터 OR/NOT 백워드 호환 확장.
//
// 입력 자동 판별 (시그니처 array $filters 불변, 결과 형태 ['sql','params'] 불변):
//   v1 (평면 AND): [{field, operator, value}, ...]
//       기존 운영자의 모든 호출 경로(api/internal-db, ScheduleRunner, segments POST/PUT 검증)와
//       100% 동일 동작. 빈 배열 → '1=1'.
//   v2 (트리)   : {op:'AND'|'OR', children:[<node>, ...]}  또는  {op:'NOT', child:<node>}
//       <node> 자체가 v1(평면 배열) 또는 v2(또 다른 트리)일 수 있어 재귀.
//       AND/OR children 빈 배열 → '1=1' (안전쪽 — 전체 발송 무효화 효과는 어차피
//       상위 호출자가 consent_guard / segments 검증으로 차단).
//       NOT 단일 자식은 'NOT (<child>)'.
//
// 호출자는 v1만 알면 동작이 변하지 않는다. 고급 모드(UI 토글 ON)에서만 v2 트리를 직렬화해
// 전달하면 본 함수가 재귀로 풀어준다.

function build_where_clause(array $filters, array $field_defs): array
{
    // 빈 입력은 기존 동작 그대로 — `1=1`로 통과.
    if (empty($filters)) {
        return ['sql' => '1=1', 'params' => []];
    }

    // v2 판별: assoc array(=object 직렬화)이면서 'op' 키 보유.
    // PHP에서 list-array(0,1,2..)는 array_is_list()로 판별; assoc은 그 반대.
    // 단, [0 => {...}]처럼 0-기반 정수 키여도 'op' 키를 가지면 v2로 본다 (방어적).
    if (isset($filters['op']) && is_string($filters['op'])) {
        return _build_where_node($filters, array_column($field_defs, null, 'field'));
    }

    // v1: 평면 배열 → 기존 AND 결합 경로 (동작 불변)
    return _build_where_v1_flat($filters, array_column($field_defs, null, 'field'));
}

/**
 * v1 평면 필터 배열(AND)을 SQL로 변환한다.
 * @internal — build_where_clause()와 _build_where_node()에서만 호출.
 */
function _build_where_v1_flat(array $filters, array $def_map): array
{
    $clauses = [];
    $params  = [];

    foreach ($filters as $f) {
        if (!is_array($f) || !isset($f['field'])) {
            // v1 평면 배열인데 leaf 형태가 아님 — 입력 오류
            throw new RuntimeException(
                '필터 노드 형식 오류: 평면 모드에서는 {field, operator, value} 항목만 허용됩니다.'
            );
        }
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
                $params[]  = cast_filter_value((string)$f['value'], $def['type']);
                break;
            case 'IN': case 'NOT IN':
                $vals = array_values(array_filter(array_map('trim', explode(',', (string)$f['value']))));
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

/**
 * v2 트리 노드(또는 v1 leaf/평면배열)를 재귀적으로 SQL로 변환한다.
 *
 * 입력 형태:
 *   {op:'AND'|'OR', children:[<node>,...]}
 *   {op:'NOT', child:<node>}
 *   또는 v1 leaf {field,operator,value}  → 평면 배열로 감싸 처리
 *   또는 v1 평면 배열 [<leaf>,...]       → AND 결합으로 처리
 *
 * @internal
 */
function _build_where_node(array $node, array $def_map): array
{
    // v1 leaf — {field,operator,value}: 단일 leaf를 1요소 평면 배열로 감싸 평면 빌더로 위임.
    if (isset($node['field']) && !isset($node['op'])) {
        return _build_where_v1_flat([$node], $def_map);
    }

    // v1 평면 배열(list-array) — leaf들의 AND.
    if (!isset($node['op']) && array_is_list($node)) {
        return _build_where_v1_flat($node, $def_map);
    }

    if (!isset($node['op'])) {
        throw new RuntimeException('필터 노드에 op 또는 field 키가 없습니다.');
    }

    $op = strtoupper((string)$node['op']);

    if ($op === 'NOT') {
        if (!isset($node['child']) || !is_array($node['child'])) {
            throw new RuntimeException('NOT 노드에는 단일 child 노드가 필요합니다.');
        }
        $sub = _build_where_node($node['child'], $def_map);
        // 빈 child(=1=1)는 NOT 의미가 모호 → NOT (1=1) 그대로 두면 0=1이 되어 전체 차단.
        // 호출자가 잘못 구성한 경우 안전쪽 결과(아무도 추출 안 됨)로 떨어지게 한다.
        return [
            'sql'    => 'NOT (' . $sub['sql'] . ')',
            'params' => $sub['params'],
        ];
    }

    if ($op !== 'AND' && $op !== 'OR') {
        throw new RuntimeException("지원하지 않는 필터 op: '{$node['op']}' (AND|OR|NOT만 허용).");
    }

    $children = $node['children'] ?? [];
    if (!is_array($children) || empty($children)) {
        // 빈 children — 무필터로 취급 (기존 v1 빈 배열 동작과 일치).
        return ['sql' => '1=1', 'params' => []];
    }

    $parts  = [];
    $params = [];
    foreach ($children as $child) {
        if (!is_array($child)) {
            throw new RuntimeException('필터 트리의 child는 배열/객체여야 합니다.');
        }
        $sub      = _build_where_node($child, $def_map);
        $parts[]  = '(' . $sub['sql'] . ')';
        $params   = array_merge($params, $sub['params']);
    }

    $glue = $op === 'AND' ? ' AND ' : ' OR ';
    return [
        'sql'    => implode($glue, $parts),
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

// ── KPI 헬퍼 (ORCH Sprint 2 — ⑲ KPI 대시보드) ───────────────────
//
// status_history 미존재 환경(테스트 등)에서도 안전하게 동작하도록 try/catch.

function _kpi_safe_select(string $sql, array $params = []): mixed
{
    try {
        $row = DB::one($sql, $params);
        if (!$row) return null;
        return array_values($row)[0] ?? null;
    } catch (Throwable $e) {
        return null;
    }
}

function kpi_sent_this_week(): array
{
    $since      = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
    $prev_since = (new DateTime('-14 days'))->format('Y-m-d H:i:s');
    $current = (int)(_kpi_safe_select(
        "SELECT COUNT(*) FROM campaigns WHERE status='sent' AND send_time >= ?",
        [$since]
    ) ?? 0);
    $prev = (int)(_kpi_safe_select(
        "SELECT COUNT(*) FROM campaigns WHERE status='sent' AND send_time >= ? AND send_time < ?",
        [$prev_since, $since]
    ) ?? 0);
    return ['value' => $current, 'prev' => $prev, 'unit' => '건'];
}

function kpi_avg_approval_minutes(): array
{
    $since      = (new DateTime('-30 days'))->format('Y-m-d H:i:s');
    $prev_since = (new DateTime('-60 days'))->format('Y-m-d H:i:s');
    $sql = "SELECT AVG(TIMESTAMPDIFF(MINUTE, h1.created_at, h2.created_at))
            FROM status_history h1
            JOIN status_history h2 ON h2.campaign_id = h1.campaign_id
            WHERE h1.to_status='awaiting_approval' AND h2.to_status='scheduling'
              AND h1.created_at >= ? AND h2.created_at > h1.created_at";
    $current = _kpi_safe_select($sql, [$since]);
    $prev_sql = str_replace('h1.created_at >= ?', 'h1.created_at >= ? AND h1.created_at < ?', $sql);
    $prev = _kpi_safe_select($prev_sql, [$prev_since, $since]);
    return [
        'value' => $current !== null ? round((float)$current, 1) : null,
        'prev'  => $prev    !== null ? round((float)$prev, 1)    : null,
        'unit'  => '분',
    ];
}

function kpi_avg_coverage_pct(): array
{
    $since      = (new DateTime('-30 days'))->format('Y-m-d H:i:s');
    $prev_since = (new DateTime('-60 days'))->format('Y-m-d H:i:s');
    // coverage_pct는 가상 컬럼이 없으면 즉석 계산
    $current = _kpi_safe_select(
        "SELECT AVG(CASE WHEN lead_count > 0 THEN sent_count * 100.0 / lead_count ELSE 0 END)
         FROM campaigns WHERE status='sent' AND send_time >= ?",
        [$since]
    );
    $prev = _kpi_safe_select(
        "SELECT AVG(CASE WHEN lead_count > 0 THEN sent_count * 100.0 / lead_count ELSE 0 END)
         FROM campaigns WHERE status='sent' AND send_time >= ? AND send_time < ?",
        [$prev_since, $since]
    );
    return [
        'value' => $current !== null ? round((float)$current, 1) : null,
        'prev'  => $prev    !== null ? round((float)$prev, 1)    : null,
        'unit'  => '%',
    ];
}

function kpi_needs_manual_review_count(): array
{
    $since      = (new DateTime('-30 days'))->format('Y-m-d H:i:s');
    $prev_since = (new DateTime('-60 days'))->format('Y-m-d H:i:s');
    $current = (int)(_kpi_safe_select(
        "SELECT COUNT(*) FROM status_history WHERE to_status='needs_manual_review' AND created_at >= ?",
        [$since]
    ) ?? 0);
    $prev = (int)(_kpi_safe_select(
        "SELECT COUNT(*) FROM status_history WHERE to_status='needs_manual_review' AND created_at >= ? AND created_at < ?",
        [$prev_since, $since]
    ) ?? 0);
    return ['value' => $current, 'prev' => $prev, 'unit' => '건'];
}

/** 트렌드 화살표 SVG (lower-is-better 인 경우 inverted=true) */
function kpi_trend_arrow_svg(?float $current, ?float $prev, bool $lower_is_better = false): string
{
    if ($current === null || $prev === null) return '';
    $delta = $current - $prev;
    if (abs($delta) < 0.01) {
        return '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-label="flat">'
             . '<path d="M2 7 L12 7" stroke="#888" stroke-width="2" stroke-linecap="round"/></svg>';
    }
    $up = $delta > 0;
    $color = $up
        ? ($lower_is_better ? '#dc3545' : '#198754')
        : ($lower_is_better ? '#198754' : '#dc3545');
    $path = $up
        ? '<path d="M7 2 L12 9 L2 9 Z" fill="' . $color . '"/>'
        : '<path d="M7 12 L12 5 L2 5 Z" fill="' . $color . '"/>';
    return '<svg width="14" height="14" viewBox="0 0 14 14" aria-label="' . ($up ? 'up' : 'down') . '">'
         . $path . '</svg>';
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

// ── Sprint 3 INFRA: run_id 자동 발급 ─────────────────────────────
// 안정 API: ensure_run_id($campaign): string
//   - $campaign['run_id']가 있으면 그대로 반환
//   - 없으면 new_uuid() 발급 + campaigns.run_id 박제 후 반환
//   - $campaign['id']가 비면 RuntimeException (박제 대상 불명)
// ScheduleRunner.run_campaign_schedule 진입부에서 1회 호출 후 모든 후속
// job_log/record_status_transition 의 run_id 인자에 전달한다.
function ensure_run_id(array $campaign): string
{
    $existing = $campaign['run_id'] ?? null;
    if (is_string($existing) && $existing !== '') {
        return $existing;
    }
    $id = $campaign['id'] ?? null;
    if (!is_string($id) || $id === '') {
        throw new RuntimeException('ensure_run_id: campaign["id"] 가 비어있습니다.');
    }
    $new = new_uuid();
    DB::exec('UPDATE campaigns SET run_id=? WHERE id=?', [$new, $id]);
    return $new;
}

// ── Sprint 1 INFRA: 스크린샷 첨부 저장소 ─────────────────────────
// 안정 API 시그니처 (시그니처 동결): screenshot_save(tmp_path, campaign_id, original_name): string
//   - data/screenshots/{campaign_id}/{timestamp}_{safe_name} 형태로 저장
//   - 상대 경로 반환 (DB 컬럼/UI에서 즉시 사용 가능)
//   - 확장자/MIME 화이트리스트(jpg/jpeg/png/webp), 5MB 상한
//   - 실패는 RuntimeException 으로 상위(ASSET zone)에 전달 → 사용자에게 400 응답

const SCREENSHOT_MAX_BYTES        = 5 * 1024 * 1024; // 5MB
const SCREENSHOT_ALLOWED_EXT      = ['jpg', 'jpeg', 'png', 'webp'];
const SCREENSHOT_ALLOWED_MIME     = ['image/jpeg', 'image/png', 'image/webp'];
const SCREENSHOT_STORAGE_SUBDIR   = 'data/screenshots';

/**
 * 업로드된 임시 파일을 영구 저장하고 상대 경로를 반환한다.
 *
 * @param string $tmp_path      업로드 임시 경로 ($_FILES['x']['tmp_name'] 등)
 * @param string $campaign_id   소속 캠페인 UUID — 디렉터리 분리 키
 * @param string $original_name 사용자 원본 파일명 — 확장자 추출/안전 문자열화에 사용
 * @return string               예: "data/screenshots/{id}/20260520_134501_proof.png"
 * @throws RuntimeException     파일 누락/크기 초과/포맷 불일치/디렉터리 생성 실패
 */
function screenshot_save(string $tmp_path, string $campaign_id, string $original_name): string
{
    if ($tmp_path === '' || !is_file($tmp_path)) {
        throw new RuntimeException('screenshot_save: 업로드 파일이 존재하지 않습니다.');
    }

    // 크기 검증 (5MB 초과 차단)
    $size = @filesize($tmp_path);
    if ($size === false) {
        throw new RuntimeException('screenshot_save: 파일 크기를 읽을 수 없습니다.');
    }
    if ($size > SCREENSHOT_MAX_BYTES) {
        throw new RuntimeException(sprintf(
            'screenshot_save: 파일 크기 초과(%d bytes, 한도 %d bytes)',
            $size, SCREENSHOT_MAX_BYTES
        ));
    }

    // 확장자 화이트리스트
    $ext = strtolower((string)pathinfo($original_name, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, SCREENSHOT_ALLOWED_EXT, true)) {
        throw new RuntimeException(sprintf(
            'screenshot_save: 허용되지 않은 확장자 "%s" (허용: %s)',
            $ext, implode(',', SCREENSHOT_ALLOWED_EXT)
        ));
    }

    // MIME 화이트리스트 (확장자 위장 차단)
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($tmp_path);
        if ($mime !== false && $mime !== null && !in_array((string)$mime, SCREENSHOT_ALLOWED_MIME, true)) {
            throw new RuntimeException(sprintf(
                'screenshot_save: 허용되지 않은 MIME "%s"', (string)$mime
            ));
        }
    }

    // campaign_id 안전화 (디렉터리 경로 위반 차단)
    $safe_camp = preg_replace('/[^A-Za-z0-9._-]/', '_', $campaign_id);
    if ($safe_camp === '' || $safe_camp === null) {
        throw new RuntimeException('screenshot_save: campaign_id 가 비어있거나 유효하지 않습니다.');
    }

    // 저장 디렉터리 보장. 프로젝트 루트는 worktree/main 모두에서 src/ 의 부모.
    $project_root = dirname(__DIR__);
    $rel_dir      = SCREENSHOT_STORAGE_SUBDIR . '/' . $safe_camp;
    $abs_dir      = $project_root . '/' . $rel_dir;

    if (!is_dir($abs_dir)) {
        if (!@mkdir($abs_dir, 0775, true) && !is_dir($abs_dir)) {
            throw new RuntimeException('screenshot_save: 저장 디렉터리 생성 실패: ' . $abs_dir);
        }
    }

    // 파일명 안전화 + 타임스탬프 prefix
    $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $original_name);
    if ($safe_name === '' || $safe_name === null) {
        $safe_name = 'screenshot.' . $ext;
    }
    $filename = date('Ymd_His') . '_' . $safe_name;
    $rel_path = $rel_dir . '/' . $filename;
    $abs_path = $abs_dir . '/' . $filename;

    // tmp_path 가 PHP 업로드인 경우 move_uploaded_file 이 정석이나, CLI/테스트 환경에서도
    // 동작해야 하므로 두 경로 모두 시도한다.
    $moved = false;
    if (is_uploaded_file($tmp_path)) {
        $moved = @move_uploaded_file($tmp_path, $abs_path);
    }
    if (!$moved) {
        $moved = @copy($tmp_path, $abs_path);
    }
    if (!$moved) {
        throw new RuntimeException('screenshot_save: 파일 저장 실패: ' . $abs_path);
    }

    @chmod($abs_path, 0644);

    return $rel_path;
}

// ── Sprint 2 DB: 코호트 통계 계산 헬퍼 (C-COHORT) ────────────────
// 안정 API 시그니처 (시그니처 동결):
//   compute_cohort_stats(array $row): array
//     입력: campaigns 1행(연관배열). lead_count, sent_count, delivered_count, bounce_count 필수.
//     반환: 입력 + coverage_pct, delivery_rate_pct 두 키 부가.
//
// 순수 함수 — DB 접근 없음. previous-cohort / cohort 응답 매핑 시 동일 식으로 사용.
//   coverage_pct       = sent_count / lead_count   (lead_count=0 → 0)
//   delivery_rate_pct  = delivered_count / sent_count (sent_count=0 → 0)
//
// 반환 행은 PHP json_encode → 클라이언트에서 .toFixed(2) 등 가공 가능하도록 float 으로 둔다.

/**
 * 캠페인 한 행에서 coverage_pct / delivery_rate_pct 를 계산해 추가한다.
 *
 * @param array $row campaigns 한 행. 최소 lead_count, sent_count, delivered_count, bounce_count 포함.
 * @return array     입력 키 모두 보존 + coverage_pct / delivery_rate_pct 추가.
 */
function compute_cohort_stats(array $row): array
{
    $lead      = (int)($row['lead_count']      ?? 0);
    $sent      = (int)($row['sent_count']      ?? 0);
    $delivered = (int)($row['delivered_count'] ?? 0);

    $coverage      = $lead > 0 ? round(($sent      / $lead) * 100, 2) : 0.0;
    $delivery_rate = $sent > 0 ? round(($delivered / $sent) * 100, 2) : 0.0;

    $row['coverage_pct']      = $coverage;
    $row['delivery_rate_pct'] = $delivery_rate;
    return $row;
}

// ── Sprint 1 INFRA: status_history 적재 ───────────────────────────
// 안정 API 시그니처 (시그니처 동결):
//   record_status_transition(campaign_id, from, to, actor='system', notes=null, run_id=null): void
//   - status_history 테이블에 1행 INSERT (append-only)
//   - actor: 'cron' | 'user' | 'system'
//   - 호출자: ORCH(api/campaigns.php, ScheduleRunner, cron/*), INFRA 자체 알림 트리거

/**
 * 캠페인 상태 전이를 status_history 에 1행 기록한다.
 *
 * INV-01(SELECT-only)은 사내 DB(InternalDB) 한정 규칙. status_history는 우리 앱 DB 이므로
 * INSERT 허용. UPDATE/DELETE는 하지 않는다(append-only).
 *
 * @param string  $campaign_id 캠페인 UUID
 * @param ?string $from        직전 상태 (생성 시 null)
 * @param string  $to          새 상태
 * @param string  $actor       'cron' | 'user' | 'system'
 * @param ?string $notes       자유 메모 (운영자 코멘트, 에러 요약 등)
 * @param ?string $run_id      Sprint 0 INFRA의 발송 1회 추적 UUID (가능하면 함께 적재)
 * @return void
 */
function record_status_transition(
    string $campaign_id,
    ?string $from,
    string $to,
    string $actor = 'system',
    ?string $notes = null,
    ?string $run_id = null
): void {
    DB::exec(
        'INSERT INTO status_history (id, campaign_id, from_status, to_status, actor, notes, run_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [new_uuid(), $campaign_id, $from, $to, $actor, $notes, $run_id, now_str()]
    );
}

