<?php
// src/helpers/filters.php — 세그먼트 필드 정의 + SQL WHERE 빌더 + 드리프트 검사
declare(strict_types=1);

/**
 * C-LEAD-COUNT (CRITICS.md §2 ★★★) — segments.last_count 대비 드리프트 검사.
 */
function check_lead_count_drift(string $segment_id, int $current_count, float $threshold = 0.5): ?string
{
    $row = DB::one('SELECT last_count FROM segments WHERE id=?', [$segment_id]);
    if ($row === null || !isset($row['last_count']) || $row['last_count'] === null) {
        return null;
    }

    $last = (int)$row['last_count'];
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

// Sprint 3 DB (③) — 필터 OR/NOT 백워드 호환 확장.
function build_where_clause(array $filters, array $field_defs): array
{
    if (empty($filters)) {
        return ['sql' => '1=1', 'params' => []];
    }

    if (isset($filters['op']) && is_string($filters['op'])) {
        return _build_where_node($filters, array_column($field_defs, null, 'field'));
    }

    return _build_where_v1_flat($filters, array_column($field_defs, null, 'field'));
}

/** @internal */
function _build_where_v1_flat(array $filters, array $def_map): array
{
    $clauses = [];
    $params  = [];

    foreach ($filters as $f) {
        if (!is_array($f) || !isset($f['field'])) {
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

/** @internal */
function _build_where_node(array $node, array $def_map): array
{
    if (isset($node['field']) && !isset($node['op'])) {
        return _build_where_v1_flat([$node], $def_map);
    }

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
