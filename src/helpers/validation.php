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
