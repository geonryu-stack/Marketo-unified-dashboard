<?php
// src/helpers/schedule.php — 발송 시각 파싱 + Marketo timezone 변환
declare(strict_types=1);

function parse_send_time(string $raw): int
{
    if (!$raw) return 0;
    return (int)strtotime(str_replace('T', ' ', $raw));
}

/**
 * SEV1 RCA(2026-05-22) 후속 — send_time → Marketo runAt 명시 timezone 변환.
 * KST 의도 입력을 UTC ISO8601로 변환한다.
 *
 * @throws RuntimeException 빈 입력 또는 파싱 실패
 */
function format_send_time_for_marketo(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        throw new RuntimeException('format_send_time_for_marketo: 빈 send_time');
    }
    $tz_name = defined('APP_INPUT_TIMEZONE') ? APP_INPUT_TIMEZONE : 'Asia/Seoul';
    try {
        $tz_local = new DateTimeZone($tz_name);
        $dt = new DateTimeImmutable($raw, $tz_local);
    } catch (Exception $e) {
        throw new RuntimeException("format_send_time_for_marketo: '$raw' 파싱 실패 ({$e->getMessage()})");
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}
