<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sprint 1 INFRA — Notifier 단위 테스트.
 *
 * 검증 범위:
 *   1) SLACK_WEBHOOK_URL 미정의/빈 문자열에서 throw 없이 통과
 *   2) CLI 환경에서 stdout 폴백 prefix `[notify:LEVEL]` 가 정확히 찍힘
 *   3) level 별 이모지(🔵🟡🔴)가 메시지에 포함됨
 *   4) 알 수 없는 level 은 info 로 강등
 *
 * 외부 네트워크(curl)는 호출되지 않도록 webhook URL 을 빈 문자열로 유지한다.
 */
final class NotifierTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // 테스트 부트스트랩에서 SLACK_WEBHOOK_URL 이 정의되어 있지 않다면 ""(빈 문자열)로
        // 정의해 외부 네트워크를 호출하지 않도록 한다.
        if (!defined('SLACK_WEBHOOK_URL')) {
            define('SLACK_WEBHOOK_URL', '');
        }
        if (!defined('RUNNING_AS_CLI')) {
            define('RUNNING_AS_CLI', true);
        }
    }

    public function testSlackDoesNotThrowWhenWebhookEmpty(): void
    {
        // throw 금지 — 알림 실패가 본업을 막아선 안 됨.
        ob_start();
        try {
            Notifier::slack('hello world', 'info');
            $this->assertTrue(true, 'no exception thrown');
        } finally {
            ob_end_clean();
        }
    }

    public function testSlackEmitsStdoutPrefixOnCli(): void
    {
        ob_start();
        Notifier::slack('cli prefix test', 'info');
        $out = (string)ob_get_clean();

        // 폴백 prefix가 라인에 포함되어야 한다.
        $this->assertStringContainsString('[notify:info]', $out);
        $this->assertStringContainsString('cli prefix test', $out);
    }

    public function testSlackEmojiPerLevel(): void
    {
        // info → 🔵
        ob_start();
        Notifier::slack('info-line', 'info');
        $out = (string)ob_get_clean();
        $this->assertStringContainsString('🔵', $out);
        $this->assertStringContainsString('[INFO]', $out);

        // warn → 🟡
        ob_start();
        Notifier::slack('warn-line', 'warn');
        $out = (string)ob_get_clean();
        $this->assertStringContainsString('🟡', $out);
        $this->assertStringContainsString('[WARN]', $out);
        $this->assertStringContainsString('[notify:warn]', $out);

        // critical → 🔴
        ob_start();
        Notifier::slack('crit-line', 'critical');
        $out = (string)ob_get_clean();
        $this->assertStringContainsString('🔴', $out);
        $this->assertStringContainsString('[CRITICAL]', $out);
        $this->assertStringContainsString('[notify:critical]', $out);
    }

    public function testUnknownLevelFallsBackToInfo(): void
    {
        ob_start();
        Notifier::slack('mystery-level', 'verbose'); // 정의되지 않은 level
        $out = (string)ob_get_clean();

        // info 로 강등되어 🔵 + [INFO] 가 사용되어야 한다.
        $this->assertStringContainsString('🔵', $out);
        $this->assertStringContainsString('[INFO]', $out);
        $this->assertStringContainsString('[notify:info]', $out);
        $this->assertStringContainsString('mystery-level', $out);
    }

    public function testSlackSignatureFrozen(): void
    {
        // 안정 API: Notifier::slack(string $message, string $level = 'info'): void
        $r = new ReflectionMethod(Notifier::class, 'slack');
        $this->assertTrue($r->isStatic(), 'slack 은 정적 메서드여야 함');
        $this->assertTrue($r->isPublic(),  'slack 은 public 이어야 함');

        $params = $r->getParameters();
        $this->assertSame(2, count($params), 'slack(message, level=info) 시그니처');

        $this->assertSame('message', $params[0]->getName());
        $this->assertFalse($params[0]->isOptional(), 'message 는 필수');

        $this->assertSame('level', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional(), 'level 은 옵션');
        $this->assertSame('info',  $params[1]->getDefaultValue());
    }
}
