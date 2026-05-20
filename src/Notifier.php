<?php
// src/Notifier.php
declare(strict_types=1);

/**
 * Notifier — Sprint 1 INFRA (HARNESS §C 관측 / C3 알림 채널 / E 킬스위치).
 *
 * 격리(needs_manual_review), 실패 누적, Bulk 지연 등 운영자가 30초 이내 인지해야 하는
 * 이벤트를 Slack incoming webhook으로 푸시한다.
 *
 * 설계 원칙:
 *   1) 본업이 알림 실패로 막히면 안 된다 — 모든 예외는 catch 후 stdout 로그로 폴백.
 *   2) SLACK_WEBHOOK_URL이 비어 있으면 no-op (개발 환경 친화).
 *   3) DRY_RUN_MODE 또는 CLI에서는 외부 호출 없이 stdout 만 — 자동화 테스트가
 *      네트워크 의존 없이 안전하게 통과한다.
 *
 * 안정 API (시그니처 동결):
 *   Notifier::slack(string $message, string $level = 'info'): void
 */
final class Notifier
{
    /** @var array<string, string> level별 이모지 prefix (HARNESS §C3) */
    private const LEVEL_EMOJI = [
        'info'     => '🔵',
        'warn'     => '🟡',
        'critical' => '🔴',
    ];

    /**
     * Slack 알림 발송.
     *
     * @param string $message 본문 (다른 zone이 만든 사람 가독 문자열)
     * @param string $level   'info' | 'warn' | 'critical' — 알 수 없는 값은 'info'로 강등
     * @return void           실패해도 throw 금지. 알림 실패는 stdout 로그로만 남김.
     */
    public static function slack(string $message, string $level = 'info'): void
    {
        $emoji = self::LEVEL_EMOJI[$level] ?? self::LEVEL_EMOJI['info'];
        $normalized_level = isset(self::LEVEL_EMOJI[$level]) ? $level : 'info';
        $line = sprintf('%s [%s] %s', $emoji, strtoupper($normalized_level), $message);

        $url = defined('SLACK_WEBHOOK_URL') ? (string)SLACK_WEBHOOK_URL : '';

        // dry-run 또는 webhook 미설정 → stdout 로그만. 본업을 막지 않는다.
        if ($url === '' || (function_exists('is_dry_run') && is_dry_run())) {
            self::stdoutLog($normalized_level, $line);
            return;
        }

        // 실제 webhook POST — 실패해도 절대 throw 하지 않는다.
        try {
            $payload = json_encode(['text' => $line], JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                throw new RuntimeException('json_encode failed');
            }

            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init failed');
            }

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);

            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp === false || $code < 200 || $code >= 300) {
                // 알림 실패 자체를 stdout으로 남겨 운영자가 인지 가능하게 한다.
                self::stdoutLog(
                    'warn',
                    sprintf('[notify:fallback] slack webhook failed (http=%d err=%s) — original: %s',
                        $code, $err !== '' ? $err : 'none', $line)
                );
                return;
            }
        } catch (\Throwable $e) {
            self::stdoutLog(
                'warn',
                sprintf('[notify:fallback] slack throw caught: %s — original: %s', $e->getMessage(), $line)
            );
            return;
        }
    }

    /**
     * stdout 폴백 로깅. CLI 외 컨텍스트(웹)에서는 stderr를 통해 Apache error_log 로 흐른다.
     * 형식: `[notify:$level] $line`  (테스트가 이 prefix를 assert)
     */
    private static function stdoutLog(string $level, string $line): void
    {
        $out = sprintf('[notify:%s] %s', $level, $line);
        if (defined('RUNNING_AS_CLI') && RUNNING_AS_CLI) {
            echo $out . PHP_EOL;
        } else {
            // 웹 컨텍스트 — Apache error log 로 흐르도록
            error_log($out);
        }
    }
}
