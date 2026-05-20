<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sprint 0 INFRA — helpers.php 신규/확장 함수의 단위 테스트.
 *
 * job_log() 의 DB 부수효과는 단위 테스트 범위 밖이므로 여기서는
 *  - 시그니처(BC)를 ReflectionFunction 으로 검증하고,
 *  - 옵션 run_id 가 주어졌을 때 CLI stdout prefix가 붙는지만 확인한다.
 */
final class HelpersTest extends TestCase
{
    // ── is_dry_run ───────────────────────────────────────────────

    public function testIsDryRunDefaultsFalseWhenUndefined(): void
    {
        // DRY_RUN_MODE 상수가 정의되지 않은 환경에서는 false 여야 한다.
        if (!defined('DRY_RUN_MODE')) {
            $this->assertFalse(is_dry_run());
        } else {
            // 테스트 부트스트랩이 이미 정의해버린 경우 — 정의된 값과 일치해야 함.
            $this->assertSame((bool)DRY_RUN_MODE && DRY_RUN_MODE === true, is_dry_run());
        }
    }

    // ── job_log signature (BC 보장) ──────────────────────────────

    public function testJobLogSignatureIsBackwardCompatible(): void
    {
        $r = new ReflectionFunction('job_log');
        $params = $r->getParameters();
        // 기존 4개 인자 + 신규 run_id 1개 = 총 5개
        $this->assertSame(5, count($params), 'job_log은 5개 인자(BC + run_id) 여야 함');

        $this->assertSame('message',     $params[0]->getName());
        $this->assertFalse($params[0]->isOptional(), 'message는 필수');

        $this->assertSame('campaign_id', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional());
        $this->assertNull($params[1]->getDefaultValue());

        $this->assertSame('step',        $params[2]->getName());
        $this->assertSame('cron',        $params[2]->getDefaultValue());

        $this->assertSame('status',      $params[3]->getName());
        $this->assertSame('info',        $params[3]->getDefaultValue());

        // 신규 추가 인자
        $this->assertSame('run_id',      $params[4]->getName());
        $this->assertTrue($params[4]->isOptional(), 'run_id는 옵션이어야 BC 보장');
        $this->assertNull($params[4]->getDefaultValue());
        $this->assertTrue($params[4]->allowsNull(), 'run_id는 nullable');
    }

    public function testJobLogPrintsRunIdPrefixOnCli(): void
    {
        // RUNNING_AS_CLI 가 정의되지 않은 phpunit 환경에서는 stdout 출력이 일어나지 않으므로
        // 동적으로 정의해 한 줄만 캡처. campaign_id=null 이므로 DB::exec 는 호출되지 않음.
        if (!defined('RUNNING_AS_CLI')) {
            define('RUNNING_AS_CLI', true);
        }
        if (!RUNNING_AS_CLI) {
            $this->markTestSkipped('RUNNING_AS_CLI=false 환경');
        }

        ob_start();
        try {
            job_log('hello world', null, 'cron', 'info', 'abcdef0123456789-deadbeef-0000-0000-000000000000');
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        // 단축 prefix(앞 8자) 가 라인에 포함되어야 한다.
        $this->assertStringContainsString('[run:abcdef01]', $out);
        $this->assertStringContainsString('hello world', $out);
    }

    public function testJobLogWithoutRunIdHasNoPrefix(): void
    {
        if (!defined('RUNNING_AS_CLI')) {
            define('RUNNING_AS_CLI', true);
        }
        if (!RUNNING_AS_CLI) {
            $this->markTestSkipped('RUNNING_AS_CLI=false 환경');
        }

        ob_start();
        try {
            job_log('plain message', null, 'cron', 'info'); // run_id 미지정 = 기존 호출 방식
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        // run_id가 없으면 prefix가 없어야 BC 유지
        $this->assertStringNotContainsString('[run:', $out);
        $this->assertStringContainsString('plain message', $out);
    }

    // ── mask_email_pii (DB Sprint 0) ─────────────────────────────

    public function testMasksStandardEmail(): void
    {
        $this->assertSame(
            'ge***@af***.com',
            mask_email_pii('geonryu@afewgoodsoft.com')
        );
    }

    public function testMasksShortLocalAndShortDomain(): void
    {
        $this->assertSame('a***@b***.io', mask_email_pii('a@b.io'));
    }

    public function testReturnsTripleStarForNonEmail(): void
    {
        $this->assertSame('***', mask_email_pii('not-an-email'));
        $this->assertSame('***', mask_email_pii(''));
        $this->assertSame('***', mask_email_pii('   '));
        $this->assertSame('***', mask_email_pii('user@localhost'));
        $this->assertSame('***', mask_email_pii('@example.com'));
        $this->assertSame('***', mask_email_pii('user@'));
    }

    public function testMultiByteLocalIsNotCorrupted(): void
    {
        $masked = mask_email_pii('한글이메일@회사명.com');
        $this->assertSame('한글***@회사***.com', $masked);
    }

    public function testTldIsPreserved(): void
    {
        $this->assertSame(
            'us***@su***.kr',
            mask_email_pii('user@sub.example.kr')
        );
    }
}
