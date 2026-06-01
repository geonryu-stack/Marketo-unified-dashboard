<?php
// src/helpers/tokens.php — 캠페인 My Token 생성 + 검증 + 인코딩
declare(strict_types=1);

function build_campaign_tokens(array $c): array
{
    return [
        ['name' => 'Emoji',     'value' => mime_header_value((string)($c['emoji']           ?? '')), 'type' => 'text'],
        ['name' => 'Title',     'value' => mime_header_value((string)($c['email_title']     ?? '')), 'type' => 'text'],
        ['name' => 'Preheader', 'value' => html_body_value((string)($c['email_preheader']  ?? '')), 'type' => 'text'],
        ['name' => 'RewardUrl', 'value' => (string)($c['reward_url'] ?? ''),                        'type' => 'text'],
    ];
}

function normalize_token_name(string $name): string
{
    return str_starts_with($name, 'my.') ? substr($name, 3) : $name;
}

/**
 * C-TOKEN-VERIFY: 기대 토큰 배열과 Marketo 응답을 비교해 불일치 목록을 반환한다.
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

/** RFC 2047 Base64 encoded-word — Marketo 비-ASCII 버그 우회 */
function mime_header_value(string $value): string
{
    if ($value === '') return '';
    if (mb_check_encoding($value, 'ASCII')) return $value;
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/** 비-ASCII → HTML 엔티티(&#xHHHH;) 변환 — Marketo 바디 삽입용 */
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
