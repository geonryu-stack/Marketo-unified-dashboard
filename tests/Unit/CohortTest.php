<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sprint 2 DB — compute_cohort_stats() 순수 함수 단위 테스트.
 *
 * 안정 API 시그니처 (시그니처 동결):
 *   compute_cohort_stats(array $row): array
 *     - coverage_pct      = round(sent_count / lead_count * 100, 2). lead_count=0이면 0.
 *     - delivery_rate_pct = round(delivered_count / sent_count * 100, 2). sent_count=0이면 0.
 *     - 입력 키는 보존, 두 키만 추가.
 */
final class CohortTest extends TestCase
{
    public function testComputeCohortStatsSignatureFrozen(): void
    {
        $this->assertTrue(
            function_exists('compute_cohort_stats'),
            'compute_cohort_stats 가 정의되어 있어야 함'
        );

        $r      = new ReflectionFunction('compute_cohort_stats');
        $params = $r->getParameters();
        $this->assertSame(1, count($params), 'compute_cohort_stats 는 1개 인자');
        $this->assertSame('row', $params[0]->getName());
        $this->assertFalse($params[0]->isOptional());

        $returnType = $r->getReturnType();
        $this->assertNotNull($returnType, '반환 타입이 선언되어 있어야 함');
        $this->assertSame('array', (string)$returnType);
    }

    public function testNormalCohortRow(): void
    {
        // 정상 케이스: 12000명 추출 → 11500명 발송(96%) → 11200명 전달(97%)
        $row = compute_cohort_stats([
            'id'              => 'c1',
            'name'            => '5월 2주차',
            'send_time'       => '2026-05-15T10:00',
            'lead_count'      => 12000,
            'sent_count'      => 11500,
            'delivered_count' => 11200,
            'bounce_count'    => 80,
        ]);

        $this->assertSame(12000, $row['lead_count']);   // 입력 보존
        $this->assertSame(11500, $row['sent_count']);
        $this->assertSame('5월 2주차', $row['name']);
        // coverage = 11500/12000*100 = 95.8333... → 95.83
        $this->assertEqualsWithDelta(95.83, $row['coverage_pct'], 0.01);
        // delivery_rate = 11200/11500*100 = 97.3913... → 97.39
        $this->assertEqualsWithDelta(97.39, $row['delivery_rate_pct'], 0.01);
    }

    public function testLeadCountZeroYieldsZeroCoverage(): void
    {
        // 추출이 0명인 회차(에지) → coverage 0, delivery_rate는 sent 기반으로 정상 계산.
        $row = compute_cohort_stats([
            'lead_count'      => 0,
            'sent_count'      => 0,
            'delivered_count' => 0,
            'bounce_count'    => 0,
        ]);
        $this->assertSame(0.0, $row['coverage_pct']);
        $this->assertSame(0.0, $row['delivery_rate_pct']);
    }

    public function testSentCountZeroYieldsZeroDeliveryRate(): void
    {
        // 추출은 있으나 발송이 0(예: scheduling 중에 어떤 이유로 sent=0인 sent 회차) → delivery_rate 0.
        $row = compute_cohort_stats([
            'lead_count'      => 1000,
            'sent_count'      => 0,
            'delivered_count' => 0,
            'bounce_count'    => 0,
        ]);
        $this->assertSame(0.0, $row['coverage_pct']);
        $this->assertSame(0.0, $row['delivery_rate_pct']);
    }

    public function testHighBounceRateScenario(): void
    {
        // 발송은 100% 커버리지지만 전달율이 낮은 케이스(bounce가 많아도 delivery_rate만 봄).
        $row = compute_cohort_stats([
            'lead_count'      => 1000,
            'sent_count'      => 1000,
            'delivered_count' => 800,
            'bounce_count'    => 200,
        ]);
        $this->assertSame(100.0, $row['coverage_pct']);
        $this->assertSame(80.0,  $row['delivery_rate_pct']);
    }

    public function testMissingKeysTreatedAsZero(): void
    {
        // 입력에 일부 키가 없어도 안전하게 0으로 처리되어야 한다.
        // (campaigns 행에 신규 회차 등 0 기본값이 항상 있지만, 방어적 가드 보장.)
        $row = compute_cohort_stats(['id' => 'x']);
        $this->assertSame(0.0, $row['coverage_pct']);
        $this->assertSame(0.0, $row['delivery_rate_pct']);
        $this->assertSame('x', $row['id']);
    }

    public function testInputKeysPreserved(): void
    {
        // 임의 키가 보존되는지(코호트 응답이 send_time 등을 그대로 통과시켜야 함).
        $row = compute_cohort_stats([
            'id'              => 'abc',
            'name'            => 'foo',
            'send_time'       => '2026-05-15T10:00',
            'lead_count'      => 100,
            'sent_count'      => 90,
            'delivered_count' => 80,
            'bounce_count'    => 5,
            'extra_field'     => 'kept',
        ]);
        $this->assertSame('abc',           $row['id']);
        $this->assertSame('foo',           $row['name']);
        $this->assertSame('2026-05-15T10:00', $row['send_time']);
        $this->assertSame('kept',          $row['extra_field']);
        $this->assertSame(90.0,            $row['coverage_pct']);
        $this->assertEqualsWithDelta(88.89, $row['delivery_rate_pct'], 0.01);
    }
}
