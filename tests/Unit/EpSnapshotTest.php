<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * MarketoAPI::buildEpSnapshot — Email Program 응답 정제 로직.
 * 외부 호출 없는 순수 함수만 검증한다.
 */
final class EpSnapshotTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../src/Marketo/MarketoAPI.php';
    }

    public function testBuildsScheduledSnapshot(): void
    {
        $program = ['result' => [['name' => 'EP-Active-A', 'status' => 'approved']]];
        $schedule = ['result' => [[
            'scheduledAt'       => '2026-05-21T01:30:00Z',
            'recipientTimeZone' => true,
        ]]];
        $snap = MarketoAPI::buildEpSnapshot(123, $program, $schedule);
        $this->assertSame(123, $snap['id']);
        $this->assertSame('EP-Active-A', $snap['name']);
        $this->assertSame('approved', $snap['status']);
        $this->assertSame('2026-05-21T01:30:00Z', $snap['scheduledAt']);
        $this->assertTrue($snap['recipientTimeZone']);
    }

    public function testEmptyScheduleProducesNull(): void
    {
        // 미예약 EP — schedule API가 errors를 돌려준 경우 호출자가 []로 변환해 전달
        $program  = ['result' => [['name' => 'EP-Draft', 'status' => 'draft']]];
        $schedule = [];
        $snap = MarketoAPI::buildEpSnapshot(7, $program, $schedule);
        $this->assertSame(7, $snap['id']);
        $this->assertSame('EP-Draft', $snap['name']);
        $this->assertSame('draft', $snap['status']);
        $this->assertNull($snap['scheduledAt']);
        $this->assertFalse($snap['recipientTimeZone']);
    }

    public function testEmptyScheduledAtStringTreatedAsNull(): void
    {
        // Marketo가 빈 문자열을 돌려주는 엣지 케이스도 null로 정규화
        $program  = ['result' => [['name' => 'X', 'status' => 'approved']]];
        $schedule = ['result' => [['scheduledAt' => '', 'recipientTimeZone' => false]]];
        $snap = MarketoAPI::buildEpSnapshot(1, $program, $schedule);
        $this->assertNull($snap['scheduledAt']);
        $this->assertFalse($snap['recipientTimeZone']);
    }

    public function testMissingProgramResultFallsBackToDefaults(): void
    {
        // 방어적: result 키가 비어 있어도 throw하지 않고 기본값으로 채움
        $snap = MarketoAPI::buildEpSnapshot(42, [], []);
        $this->assertSame(42, $snap['id']);
        $this->assertSame('', $snap['name']);
        $this->assertSame('draft', $snap['status']);
        $this->assertNull($snap['scheduledAt']);
        $this->assertFalse($snap['recipientTimeZone']);
    }

    public function testRecipientTimeZoneCastToBool(): void
    {
        // truthy 값이 들어와도 bool로 정규화
        $program  = ['result' => [['name' => 'A', 'status' => 'approved']]];
        $schedule = ['result' => [['scheduledAt' => '2026-05-21T01:30:00Z', 'recipientTimeZone' => 1]]];
        $snap = MarketoAPI::buildEpSnapshot(1, $program, $schedule);
        $this->assertTrue($snap['recipientTimeZone']);
        $this->assertIsBool($snap['recipientTimeZone']);
    }
}
