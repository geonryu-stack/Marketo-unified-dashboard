<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * VVIP 우선순위 Suppression — 순수(=DB 무관) 정적 메서드 단위 테스트.
 *
 * DB 의존 메서드(computeEmails / persistPool / clearForCampaign /
 * findBlockingActiveCampaign / sanitizeInput)는 운영 통합 검증 영역.
 */
final class SuppressionTest extends TestCase
{
    // ── extractSendDate ───────────────────────────────────────────

    public function testExtractSendDateHandlesIsoFormat(): void
    {
        $this->assertSame('2026-05-21', Suppression::extractSendDate('2026-05-21T10:00'));
    }

    public function testExtractSendDateHandlesSpaceFormat(): void
    {
        $this->assertSame('2026-05-21', Suppression::extractSendDate('2026-05-21 10:00:00'));
    }

    public function testExtractSendDateHandlesDateOnly(): void
    {
        $this->assertSame('2026-05-21', Suppression::extractSendDate('2026-05-21'));
    }

    public function testExtractSendDateReturnsEmptyOnGarbage(): void
    {
        $this->assertSame('', Suppression::extractSendDate(''));
        $this->assertSame('', Suppression::extractSendDate('not-a-date'));
    }

    // ── decode ────────────────────────────────────────────────────

    public function testDecodeHandlesEmpty(): void
    {
        $this->assertSame([], Suppression::decode(null));
        $this->assertSame([], Suppression::decode(''));
        $this->assertSame([], Suppression::decode('[]'));
    }

    public function testDecodeParsesArray(): void
    {
        $this->assertSame(['a', 'b', 'c'], Suppression::decode('["a","b","c"]'));
    }

    public function testDecodeFiltersBlanksAndNonStrings(): void
    {
        // 빈 문자열·공백·비문자열은 제거되어야 함
        $out = Suppression::decode('["a","",  "  b  ", 42, null, "c"]');
        $this->assertSame(['a', 'b', 'c'], $out);
    }

    public function testDecodeHandlesMalformed(): void
    {
        // JSON 파싱 실패 / 배열이 아닌 경우 모두 빈 배열 폴백
        $this->assertSame([], Suppression::decode('not json'));
        $this->assertSame([], Suppression::decode('{"a":1}'));
    }

    // ── applyToWhereClause ───────────────────────────────────────

    public function testApplyToWhereClauseNoopsOnEmptySuppress(): void
    {
        $r = Suppression::applyToWhereClause('a=1', ['x'], '`email`', []);
        $this->assertSame('a=1', $r['sql']);
        $this->assertSame(['x'], $r['params']);
    }

    public function testApplyToWhereClauseAppendsNotIn(): void
    {
        $r = Suppression::applyToWhereClause('a=1', ['x'], '`email`', ['p@x.com', 'q@x.com']);
        $this->assertStringContainsString('(a=1) AND `email` NOT IN (?,?)', $r['sql']);
        $this->assertSame(['x', 'p@x.com', 'q@x.com'], $r['params']);
    }

    public function testApplyToWhereClauseChunksOverThousand(): void
    {
        $emails = [];
        for ($i = 0; $i < 1500; $i++) {
            $emails[] = "u{$i}@x.com";
        }
        $r = Suppression::applyToWhereClause('a=1', [], '`email`', $emails);
        // 1000 + 500 두 청크 → NOT IN 절이 2개
        $this->assertSame(2, substr_count($r['sql'], 'NOT IN'));
        $this->assertCount(1500, $r['params']);
    }

    // ── applyToBypassList ────────────────────────────────────────

    public function testApplyToBypassListFiltersSuppressedEmails(): void
    {
        $r = Suppression::applyToBypassList(
            ['alice@x.com', 'bob@x.com|JP', 'carol@x.com|KR'],
            ['bob@x.com']
        );
        $this->assertSame(1, $r['skipped']);
        $this->assertCount(2, $r['leads']);
        $this->assertSame('alice@x.com', $r['leads'][0]);
        $this->assertSame(['email' => 'carol@x.com', 'country' => 'KR'], $r['leads'][1]);
    }

    public function testApplyToBypassListIsCaseInsensitive(): void
    {
        // 대소문자 무관하게 매칭 — Marketo는 이메일 lowercase 정규화 가정
        $r = Suppression::applyToBypassList(['Alice@X.com'], ['alice@x.com']);
        $this->assertSame(1, $r['skipped']);
        $this->assertEmpty($r['leads']);
    }

    public function testApplyToBypassListSkipsEmptyEntries(): void
    {
        $r = Suppression::applyToBypassList(['', 'a@x.com', '|', 'b@x.com|US'], []);
        $this->assertCount(2, $r['leads']);
        $this->assertSame(0, $r['skipped']);
    }

    // ── 상수 stability (HARNESS 안전망) ────────────────────────────

    public function testActiveStatesContainsExpectedSet(): void
    {
        // 충돌 검사·박제 조회가 같은 status set 을 참조해야 한다 —
        // 둘 중 하나가 빠지면 잘못된 NOT IN 또는 false-pass conflict 발생.
        $expected = ['awaiting_approval', 'scheduling', 'bulk_polling', 'bulk_finalizing',
                     'scheduled', 'needs_manual_review'];
        $this->assertSame($expected, Suppression::ACTIVE_STATES);
    }
}
