<?php
// src/helpers/validation.php — 입력값 검증 헬퍼
declare(strict_types=1);

/**
 * 리드별 cap 입력값을 0~9999 정수로 정규화. 음수/NaN 차단.
 * 누락(키 자체 없음) 시 $default 반환.
 */
function sanitize_cap_int(mixed $raw, int $default): int
{
    if ($raw === null || $raw === '') return $default;
    if (!is_numeric($raw)) {
        json_err('cap 값은 0 이상 9999 이하의 정수여야 합니다.', 400);
    }
    $n = (int)$raw;
    if ($n < 0 || $n > 9999) {
        json_err('cap 값은 0 이상 9999 이하의 정수여야 합니다.', 400);
    }
    return $n;
}

/**
 * C-INPUT-SANITY (CRITICS.md §2 ★★☆) — 캠페인 입력 필드 검증.
 * 위반 시 json_err(400)로 즉시 종료. 빈값은 선택 입력으로 간주해 검증 skip.
 */
function assert_campaign_input(array $body): void
{
    $errors = [];

    $title = (string)($body['email_title'] ?? '');
    if ($title !== '' && mb_strlen($title, 'UTF-8') > 100) {
        $errors[] = 'email_title: 100자 이하여야 합니다 (현재 ' . mb_strlen($title, 'UTF-8') . '자)';
    }

    $preheader = (string)($body['email_preheader'] ?? '');
    if ($preheader !== '' && mb_strlen($preheader, 'UTF-8') > 140) {
        $errors[] = 'email_preheader: 140자 이하여야 합니다 (현재 ' . mb_strlen($preheader, 'UTF-8') . '자)';
    }

    $url = (string)($body['reward_url'] ?? '');
    if ($url !== '') {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($parsed === false || !in_array($scheme, ['http', 'https'], true) || empty($parsed['host'])) {
            $errors[] = 'reward_url: http:// 또는 https:// 로 시작하는 유효한 URL이어야 합니다';
        }
    }

    $emoji = (string)($body['emoji'] ?? '');
    if ($emoji !== '') {
        $gc = function_exists('grapheme_strlen') ? grapheme_strlen($emoji) : mb_strlen($emoji, 'UTF-8');
        if ($gc !== false && $gc > 1) {
            $errors[] = 'emoji: 1개 이모지만 허용됩니다 (현재 ' . $gc . '개 grapheme)';
        }
    }

    if (!empty($errors)) {
        json_err('입력 검증 실패: ' . implode('; ', $errors), 400);
    }
}

// ── 세그먼트 유형 (IMPROVEMENT_SPEC #1 Option A) ─────────────────

const SEGMENT_TYPES = ['active', 'reengagement', 'transactional', 'lifecycle', 'custom'];

/**
 * 세그먼트 유형 입력값을 검증. 빈값/null이면 $default 반환.
 * 허용값 외이면 json_err(400).
 */
function validate_segment_type(mixed $raw, string $default = 'active'): string
{
    if ($raw === null || $raw === '') return $default;
    $val = (string)$raw;
    if (!in_array($val, SEGMENT_TYPES, true)) {
        json_err('segment type은 ' . implode(', ', SEGMENT_TYPES) . ' 중 하나여야 합니다.', 400);
    }
    return $val;
}

/**
 * 유형별 preview 가드 힌트. 운영자가 consent_guard 설정을 올바르게 하도록 안내.
 * 추출 시점에는 적용되지 않으며, 정보 제공만 한다.
 */
function get_segment_type_guard_hint(string $type): array
{
    return match ($type) {
        'active' => [
            'type'                      => 'active',
            'message'                   => '활성 유저 세그먼트: 동의 + 활성 가드가 적용됩니다.',
            'recommended_consent_guard' => true,
        ],
        'reengagement' => [
            'type'                      => 'reengagement',
            'message'                   => 'Re-engagement 세그먼트: consent_guard를 OFF하고, is_active=0 필터를 추가하는 것을 권장합니다.',
            'recommended_consent_guard' => false,
        ],
        'transactional' => [
            'type'                      => 'transactional',
            'message'                   => '거래성 알림 세그먼트: 법적 필수 발송이므로 가드를 최소화할 수 있습니다.',
            'recommended_consent_guard' => false,
        ],
        'lifecycle' => [
            'type'                      => 'lifecycle',
            'message'                   => '라이프사이클 세그먼트: 동의 + 활성 가드 권장. 이벤트 기간 필터가 설정되었는지 확인하세요.',
            'recommended_consent_guard' => true,
        ],
        default => [
            'type'                      => $type,
            'message'                   => '',
            'recommended_consent_guard' => true,
        ],
    };
}
