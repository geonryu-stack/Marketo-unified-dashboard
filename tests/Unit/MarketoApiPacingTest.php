<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PR-4 (α) — MarketoAPI::pacingMicroseconds 순수 함수 단위 테스트.
 *
 * config 상수 (MARKETO_API_PACE_US / MARKETO_API_PACE_MIN_LEADS) 가 정의된
 * bootstrap 환경을 가정. 미정의 시 0 반환 (kill switch).
 */
final class MarketoApiPacingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../src/Marketo/MarketoAPI.php';
        // 테스트 환경: config.php 미로드 → 상수 미정의 → pacing 자동 비활성.
        // 본 테스트는 *상수 미정의 시 0* 케이스만 보장한다. 정의된 케이스는
        // runInSeparateProcess 가 필요하지만 비용 대비 효용 낮음 — kill switch 만 검증.
    }

    public function testReturnsZeroWhenConfigUndefined(): void
    {
        // bootstrap 에서 MARKETO_API_PACE_MIN_LEADS 미정의 → 항상 0 (kill switch)
        $this->assertSame(0, MarketoAPI::pacingMicroseconds(0));
        $this->assertSame(0, MarketoAPI::pacingMicroseconds(500));
        $this->assertSame(0, MarketoAPI::pacingMicroseconds(60000));
    }

    public function testSignatureFrozen(): void
    {
        // 회귀 가드 — 시그니처가 깨지면 ScheduleRunner 호출이 무너짐
        $rm = new ReflectionMethod('MarketoAPI', 'pacingMicroseconds');
        $this->assertTrue($rm->isStatic());
        $this->assertTrue($rm->isPublic());
        $this->assertSame('int', (string)$rm->getReturnType());
        $params = $rm->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('lead_count', $params[0]->getName());
    }
}
