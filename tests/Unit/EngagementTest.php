<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * MarketoAPI::tallyEngagement — Activity API 응답 → engagement 카운트 정제 로직.
 * 외부 호출 없는 순수 함수만 검증한다.
 *
 * Sprint 2 정책 (단순 카운트):
 *  - 같은 leadId의 같은 type 중복도 dedupe 하지 않는다.
 *  - bounce = soft_bounce + hard_bounce (합산).
 *  - 알 수 없는 activityTypeId는 무시.
 */
final class EngagementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../src/Marketo/MarketoAPI.php';
    }

    public function testEmptyActivitiesProduceZeroCounts(): void
    {
        $counts = MarketoAPI::tallyEngagement([]);
        $this->assertSame(0, $counts['sent']);
        $this->assertSame(0, $counts['delivered']);
        $this->assertSame(0, $counts['bounce']);
        $this->assertSame(0, $counts['soft_bounce']);
        $this->assertSame(0, $counts['hard_bounce']);
        $this->assertSame(0, $counts['open']);
        $this->assertSame(0, $counts['click']);
        $this->assertSame(0, $counts['unsubscribe']);
    }

    public function testCountsAllStandardActivityTypes(): void
    {
        $activities = [
            ['activityTypeId' => 6],  // sent
            ['activityTypeId' => 6],
            ['activityTypeId' => 7],  // delivered
            ['activityTypeId' => 10], // open
            ['activityTypeId' => 13], // click
            ['activityTypeId' => 11], // soft bounce
            ['activityTypeId' => 12], // hard bounce
            ['activityTypeId' => 22], // unsubscribe
        ];
        $counts = MarketoAPI::tallyEngagement($activities);
        $this->assertSame(2, $counts['sent']);
        $this->assertSame(1, $counts['delivered']);
        $this->assertSame(1, $counts['soft_bounce']);
        $this->assertSame(1, $counts['hard_bounce']);
        $this->assertSame(2, $counts['bounce'], 'bounce는 soft+hard 합산');
        $this->assertSame(1, $counts['open']);
        $this->assertSame(1, $counts['click']);
        $this->assertSame(1, $counts['unsubscribe']);
    }

    public function testDuplicateLeadActivitiesAreNotDeduped(): void
    {
        // Sprint 2 정책: 같은 leadId의 같은 type 중복도 단순 카운트.
        $activities = [
            ['activityTypeId' => 10, 'leadId' => 999],
            ['activityTypeId' => 10, 'leadId' => 999],
            ['activityTypeId' => 10, 'leadId' => 999],
            ['activityTypeId' => 13, 'leadId' => 999],
            ['activityTypeId' => 13, 'leadId' => 999],
        ];
        $counts = MarketoAPI::tallyEngagement($activities);
        $this->assertSame(3, $counts['open'], 'open은 중복 dedupe 안 함');
        $this->assertSame(2, $counts['click'], 'click도 중복 dedupe 안 함');
    }

    public function testUnknownActivityTypeIdsAreIgnored(): void
    {
        $activities = [
            ['activityTypeId' => 6],   // sent
            ['activityTypeId' => 999], // unknown — 무시
            ['activityTypeId' => 1],   // visit web page — 무시 (engagement 아님)
            ['activityTypeId' => 7],   // delivered
        ];
        $counts = MarketoAPI::tallyEngagement($activities);
        $this->assertSame(1, $counts['sent']);
        $this->assertSame(1, $counts['delivered']);
        $this->assertSame(0, $counts['open']);
        $this->assertSame(0, $counts['click']);
    }

    public function testMissingActivityTypeIdTreatedAsUnknown(): void
    {
        // 방어적: activityTypeId 키가 없으면 0으로 캐스팅돼 어떤 타입도 매치 안 함
        $activities = [
            ['leadId' => 1],
            ['leadId' => 2, 'activityTypeId' => null],
            ['activityTypeId' => 6], // 카운트되는 정상 행
        ];
        $counts = MarketoAPI::tallyEngagement($activities);
        $this->assertSame(1, $counts['sent']);
        $this->assertSame(0, $counts['open']);
    }

    public function testReturnShapeMatchesStableApi(): void
    {
        // 안정 API 시그니처 — 다른 트랙(ORCH)이 의존하므로 키셋 보장
        $counts = MarketoAPI::tallyEngagement([]);
        $expected_keys = [
            'sent', 'delivered', 'bounce',
            'soft_bounce', 'hard_bounce',
            'open', 'click', 'unsubscribe',
        ];
        foreach ($expected_keys as $k) {
            $this->assertArrayHasKey($k, $counts, "키 누락: $k");
            $this->assertIsInt($counts[$k], "$k 는 int여야 함");
        }
    }
}
