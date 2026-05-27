<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 발송 결과 누적 MVP — tallyEngagementByDate + extractActivityDate 순수함수 검증.
 */
final class EngagementByDateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../src/Marketo/MarketoAPI.php';
    }

    // ── extractActivityDate ──────────────────────────────────────

    public function testExtractActivityDateIsoZ(): void
    {
        $this->assertSame('2026-05-21', MarketoAPI::extractActivityDate('2026-05-21T03:45:12Z'));
    }

    public function testExtractActivityDateWithOffset(): void
    {
        $this->assertSame('2026-05-21', MarketoAPI::extractActivityDate('2026-05-21T03:45:12+0900'));
    }

    public function testExtractActivityDateSpaceFormat(): void
    {
        $this->assertSame('2026-05-21', MarketoAPI::extractActivityDate('2026-05-21 03:45:12'));
    }

    public function testExtractActivityDateEmpty(): void
    {
        $this->assertSame('', MarketoAPI::extractActivityDate(''));
        $this->assertSame('', MarketoAPI::extractActivityDate('not a date'));
    }

    // ── tallyEngagementByDate ────────────────────────────────────

    public function testEmptyInputReturnsEmptyArray(): void
    {
        $this->assertSame([], MarketoAPI::tallyEngagementByDate([]));
    }

    public function testGroupsBySingleDate(): void
    {
        $acts = [
            ['activityTypeId' => 6,  'activityDate' => '2026-05-21T10:00:00Z'],
            ['activityTypeId' => 6,  'activityDate' => '2026-05-21T10:01:00Z'],
            ['activityTypeId' => 7,  'activityDate' => '2026-05-21T10:02:00Z'],
            ['activityTypeId' => 10, 'activityDate' => '2026-05-21T11:00:00Z'],
            ['activityTypeId' => 13, 'activityDate' => '2026-05-21T11:30:00Z'],
        ];
        $out = MarketoAPI::tallyEngagementByDate($acts);
        $this->assertArrayHasKey('2026-05-21', $out);
        $this->assertSame(2, $out['2026-05-21']['sent']);
        $this->assertSame(1, $out['2026-05-21']['delivered']);
        $this->assertSame(1, $out['2026-05-21']['open']);
        $this->assertSame(1, $out['2026-05-21']['click']);
        $this->assertSame(0, $out['2026-05-21']['unsubscribe']);
    }

    public function testGroupsAcrossMultipleDates(): void
    {
        $acts = [
            ['activityTypeId' => 6,  'activityDate' => '2026-05-21T10:00:00Z'],
            ['activityTypeId' => 10, 'activityDate' => '2026-05-22T03:00:00Z'],
            ['activityTypeId' => 10, 'activityDate' => '2026-05-22T15:00:00Z'],
            ['activityTypeId' => 13, 'activityDate' => '2026-05-23T08:00:00Z'],
            ['activityTypeId' => 22, 'activityDate' => '2026-05-28T12:00:00Z'],
        ];
        $out = MarketoAPI::tallyEngagementByDate($acts);
        $this->assertCount(4, $out);
        $this->assertSame(1, $out['2026-05-21']['sent']);
        $this->assertSame(2, $out['2026-05-22']['open']);
        $this->assertSame(1, $out['2026-05-23']['click']);
        $this->assertSame(1, $out['2026-05-28']['unsubscribe']);
    }

    public function testSoftAndHardBounceCombinedAsBounce(): void
    {
        $acts = [
            ['activityTypeId' => 11, 'activityDate' => '2026-05-21T10:00:00Z'],
            ['activityTypeId' => 12, 'activityDate' => '2026-05-21T11:00:00Z'],
        ];
        $out = MarketoAPI::tallyEngagementByDate($acts);
        $this->assertSame(2, $out['2026-05-21']['bounce']);
    }

    public function testUnknownActivityTypeIsIgnored(): void
    {
        $acts = [
            ['activityTypeId' => 999, 'activityDate' => '2026-05-21T10:00:00Z'],
            ['activityTypeId' => 6,   'activityDate' => '2026-05-21T11:00:00Z'],
        ];
        $out = MarketoAPI::tallyEngagementByDate($acts);
        $this->assertSame(1, $out['2026-05-21']['sent']);
    }

    public function testMissingActivityDateSkippedNotCrash(): void
    {
        $acts = [
            ['activityTypeId' => 6], // activityDate 없음
            ['activityTypeId' => 6, 'activityDate' => '2026-05-21T10:00:00Z'],
        ];
        $out = MarketoAPI::tallyEngagementByDate($acts);
        $this->assertSame(1, $out['2026-05-21']['sent']);
        $this->assertCount(1, $out);
    }

    public function testResultIsSortedByDate(): void
    {
        $acts = [
            ['activityTypeId' => 6, 'activityDate' => '2026-05-23T10:00:00Z'],
            ['activityTypeId' => 6, 'activityDate' => '2026-05-21T10:00:00Z'],
            ['activityTypeId' => 6, 'activityDate' => '2026-05-22T10:00:00Z'],
        ];
        $out  = MarketoAPI::tallyEngagementByDate($acts);
        $keys = array_keys($out);
        $this->assertSame(['2026-05-21', '2026-05-22', '2026-05-23'], $keys);
    }

    // ── Should #7: review 가 지적한 보강 케이스 ─────────────────

    public function testActivityTypeIdAsStringStillCounts(): void
    {
        // Marketo 응답에서 activityTypeId 가 문자열 '6' 으로 오는 경우 — (int) 캐스팅 후 match.
        $acts = [
            ['activityTypeId' => '6',  'activityDate' => '2026-05-21T10:00:00Z'],
            ['activityTypeId' => '10', 'activityDate' => '2026-05-21T11:00:00Z'],
        ];
        $out = MarketoAPI::tallyEngagementByDate($acts);
        $this->assertSame(1, $out['2026-05-21']['sent']);
        $this->assertSame(1, $out['2026-05-21']['open']);
    }

    public function testInvalidActivityDateSkipsRowWithoutCrash(): void
    {
        // activityDate 가 garbage 인 row 는 skip, 정상 row 는 그대로 카운트.
        $acts = [
            ['activityTypeId' => 6, 'activityDate' => 'not-a-date'],
            ['activityTypeId' => 6, 'activityDate' => '2026-05-21T10:00:00Z'],
        ];
        $out = MarketoAPI::tallyEngagementByDate($acts);
        $this->assertCount(1, $out);
        $this->assertSame(1, $out['2026-05-21']['sent']);
    }

    public function testTimezonePrefixPreserved(): void
    {
        // 의도된 동작: ISO 의 날짜 prefix 'YYYY-MM-DD' 를 그대로 사용 (timezone 정규화 안 함).
        // KST 23:30 의 활동은 Marketo 가 '2026-05-21T23:30:00+0900' 으로 보내면 그대로 2026-05-21.
        // UTC 변환 시 2026-05-21T14:30:00Z 가 되지만 본 구현은 prefix 기준이라 정규화 무관.
        $kst = ['activityTypeId' => 6, 'activityDate' => '2026-05-21T23:30:00+0900'];
        $utc = ['activityTypeId' => 6, 'activityDate' => '2026-05-21T14:30:00Z'];
        $out_kst = MarketoAPI::tallyEngagementByDate([$kst]);
        $out_utc = MarketoAPI::tallyEngagementByDate([$utc]);
        $this->assertArrayHasKey('2026-05-21', $out_kst);
        $this->assertArrayHasKey('2026-05-21', $out_utc);
    }

    public function testMixedTypesSameDateAllPresent(): void
    {
        // 같은 날 sent/delivered/open/click/unsubscribe 혼합 — 한 row 에 모든 키 존재.
        $acts = [
            ['activityTypeId' => 6,  'activityDate' => '2026-05-21T10:00:00Z'],
            ['activityTypeId' => 7,  'activityDate' => '2026-05-21T10:00:30Z'],
            ['activityTypeId' => 10, 'activityDate' => '2026-05-21T11:00:00Z'],
            ['activityTypeId' => 13, 'activityDate' => '2026-05-21T11:05:00Z'],
            ['activityTypeId' => 22, 'activityDate' => '2026-05-21T12:00:00Z'],
        ];
        $out = MarketoAPI::tallyEngagementByDate($acts);
        $row = $out['2026-05-21'];
        foreach (['sent','delivered','bounce','open','click','unsubscribe'] as $k) {
            $this->assertArrayHasKey($k, $row, "key {$k} missing");
        }
        $this->assertSame(1, $row['sent']);
        $this->assertSame(1, $row['delivered']);
        $this->assertSame(1, $row['open']);
        $this->assertSame(1, $row['click']);
        $this->assertSame(1, $row['unsubscribe']);
        $this->assertSame(0, $row['bounce']);
    }

    public function testEngagementTypeIdsAccessor(): void
    {
        // cron 이 ENGAGEMENT_TYPE_IDS 를 외부에서 받아 쓰는 패턴 회귀 가드.
        $ids = MarketoAPI::engagementTypeIds();
        $this->assertSame(6,  $ids['sent']);
        $this->assertSame(7,  $ids['delivered']);
        $this->assertSame(11, $ids['soft_bounce']);
        $this->assertSame(12, $ids['hard_bounce']);
        $this->assertSame(10, $ids['open']);
        $this->assertSame(13, $ids['click']);
        $this->assertSame(22, $ids['unsubscribe']);
    }
}
