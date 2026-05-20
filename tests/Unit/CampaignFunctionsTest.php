<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CampaignFunctionsTest extends TestCase
{
    // ── build_campaign_tokens ─────────────────────────────────────

    public function testTokenNamesAreCapitalized(): void
    {
        $tokens = build_campaign_tokens([
            'emoji'           => '🎉',
            'email_title'     => 'Test Title',
            'email_preheader' => 'Preheader',
            'reward_url'      => 'https://example.com',
        ]);
        $this->assertSame(
            ['Emoji', 'Title', 'Preheader', 'RewardUrl'],
            array_column($tokens, 'name')
        );
    }

    public function testHeaderTokensAreMimeEncoded(): void
    {
        $tokens = build_campaign_tokens([
            'emoji'           => '🎉',
            'email_title'     => '한국어 제목',
            'email_preheader' => '프리헤더',
            'reward_url'      => 'https://example.com',
        ]);
        $map = array_column($tokens, 'value', 'name');
        // 헤더 토큰(Emoji, Title)은 MIME encoded-word
        $this->assertStringStartsWith('=?UTF-8?B?', $map['Emoji']);
        $this->assertStringStartsWith('=?UTF-8?B?', $map['Title']);
        // 바디 토큰(Preheader)은 HTML 엔티티
        $this->assertStringContainsString('&#x', $map['Preheader']);
        $this->assertStringNotContainsString('=?UTF-8?B?', $map['Preheader']);
        // URL은 인코딩 없음
        $this->assertSame('https://example.com', $map['RewardUrl']);
    }

    public function testPreheaderHtmlEntityDecoding(): void
    {
        $tokens = build_campaign_tokens(['email_preheader' => '안녕']);
        $map    = array_column($tokens, 'value', 'name');
        // 디코딩하면 원래 문자로 복원되어야 함
        $decoded = html_entity_decode($map['Preheader'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame('안녕', $decoded);
    }

    public function testAsciiTokensAreNotEncoded(): void
    {
        $tokens = build_campaign_tokens([
            'email_title'     => 'Hello World',
            'email_preheader' => 'Simple text',
        ]);
        $map = array_column($tokens, 'value', 'name');
        $this->assertSame('Hello World', $map['Title']);   // ASCII → 그대로
        $this->assertSame('Simple text', $map['Preheader']); // ASCII → 그대로
    }

    public function testRewardUrlPassedThrough(): void
    {
        $url    = 'https://example.com/reward?id=abc&ref=email';
        $tokens = build_campaign_tokens(['reward_url' => $url]);
        $map    = array_column($tokens, 'value', 'name');
        $this->assertSame($url, $map['RewardUrl']);
    }

    public function testMissingFieldsDefaultToEmpty(): void
    {
        foreach (build_campaign_tokens([]) as $token) {
            $this->assertSame('', $token['value'], "token {$token['name']} should be empty");
        }
    }

    // ── parse_send_time ───────────────────────────────────────────

    public function testFullDatetimeWithTSeparator(): void
    {
        $ts = parse_send_time('2026-05-01T10:00');
        $this->assertGreaterThan(0, $ts);
        $this->assertSame('2026-05-01 10:00', date('Y-m-d H:i', $ts));
    }

    public function testEmptyStringReturnsZero(): void
    {
        $this->assertSame(0, parse_send_time(''));
    }

    public function testTimeOnlyStringResolvesToNonZero(): void
    {
        // strtotime('10:00') returns today-at-10:00; current impl accepts it.
        // Production callers (duplicate action) always construct full datetime
        // before calling, so this is safe.
        $this->assertGreaterThan(0, parse_send_time('10:00'));
    }

    // ── status_label / status_badge_class (결재 워크플로) ─────────

    public function testAwaitingApprovalLabel(): void
    {
        $this->assertSame('결재 대기', status_label('awaiting_approval'));
    }

    public function testAwaitingApprovalBadgeIsWarning(): void
    {
        // info → warning 으로 변경되어 결재 대기 카드의 시선 강조 효과
        $this->assertSame('warning', status_badge_class('awaiting_approval'));
    }

    public function testLegacyTestSentLabelPreserved(): void
    {
        // 마이그레이션 직전 데이터 호환을 위해 라벨은 유지
        $this->assertSame('테스트 발송 완료', status_label('test_sent'));
        $this->assertSame('info', status_badge_class('test_sent'));
    }

    public function testUnknownStatusFallback(): void
    {
        $this->assertSame('foobar', status_label('foobar'));
        $this->assertSame('secondary', status_badge_class('foobar'));
    }

    public function testCoreStatesUnchanged(): void
    {
        // 결재 워크플로 도입 후에도 기존 상태들의 의미/배지는 유지되어야 함
        $this->assertSame('예약 완료', status_label('scheduled'));
        $this->assertSame('success', status_badge_class('scheduled'));
        $this->assertSame('실패', status_label('failed'));
        $this->assertSame('danger', status_badge_class('failed'));
        $this->assertSame('수동 검토 필요', status_label('needs_manual_review'));
        $this->assertSame('danger', status_badge_class('needs_manual_review'));
    }
}
