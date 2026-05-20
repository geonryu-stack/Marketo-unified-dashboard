<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * C-TOKEN-VERIFY (CRITICS.md §2 ★★★) — echo-back 비교 로직 단위 테스트.
 *
 * 외부 Marketo 호출은 ScheduleRunner.php 안에서 일어나므로 여기서는 비교 로직만 검증한다.
 * 입력은 build_campaign_tokens() 결과 + Marketo 응답 형태(my. 접두사 혼재 가능)의 두 배열.
 */
final class TokenVerifyTest extends TestCase
{
    // ── normalize_token_name ──────────────────────────────────────

    public function testNormalizeStripsMyPrefix(): void
    {
        $this->assertSame('Emoji', normalize_token_name('my.Emoji'));
        $this->assertSame('Title', normalize_token_name('my.Title'));
    }

    public function testNormalizeKeepsBareName(): void
    {
        // Marketo가 접두사 없이 돌려주는 경우도 안전하게 통과해야 함
        $this->assertSame('Emoji', normalize_token_name('Emoji'));
        $this->assertSame('RewardUrl', normalize_token_name('RewardUrl'));
    }

    public function testNormalizeOnlyStripsOnePrefix(): void
    {
        // 'my.' 접두사를 1회만 제거 — 이중 접두사는 입력 그대로의 의도를 보존
        $this->assertSame('my.Emoji', normalize_token_name('my.my.Emoji'));
    }

    // ── diff_campaign_tokens — 통과 케이스 ────────────────────────

    public function testAllTokensMatchWithMyPrefix(): void
    {
        // Marketo 응답이 'my.' 접두사가 붙은 형태로 와도 매치되어야 함
        $expected = build_campaign_tokens([
            'emoji'           => '',
            'email_title'     => 'Hello',
            'email_preheader' => 'Sub',
            'reward_url'      => 'https://example.com',
        ]);
        $actual = [
            ['name' => 'my.Emoji',     'value' => ''],
            ['name' => 'my.Title',     'value' => 'Hello'],
            ['name' => 'my.Preheader', 'value' => 'Sub'],
            ['name' => 'my.RewardUrl', 'value' => 'https://example.com'],
        ];
        $this->assertSame([], diff_campaign_tokens($expected, $actual));
    }

    public function testAllTokensMatchWithoutMyPrefix(): void
    {
        $expected = build_campaign_tokens([
            'emoji'           => '',
            'email_title'     => 'Hello',
            'email_preheader' => 'Sub',
            'reward_url'      => 'https://example.com',
        ]);
        $actual = [
            ['name' => 'Emoji',     'value' => ''],
            ['name' => 'Title',     'value' => 'Hello'],
            ['name' => 'Preheader', 'value' => 'Sub'],
            ['name' => 'RewardUrl', 'value' => 'https://example.com'],
        ];
        $this->assertSame([], diff_campaign_tokens($expected, $actual));
    }

    public function testMatchWithEncodedNonAsciiValues(): void
    {
        // 실제 빌드된 Title은 MIME encoded-word("=?UTF-8?B?...?="), Preheader는 HTML entity("&#x...;").
        // Marketo에 주입된 값과 GET으로 돌려받은 값이 같으면 통과.
        $expected = build_campaign_tokens([
            'emoji'           => '🎉',
            'email_title'     => '한국어',
            'email_preheader' => '안녕',
            'reward_url'      => 'https://example.com/r',
        ]);
        // 응답이 expected와 정확히 같은 인코딩 값으로 돌아오는 시나리오
        $actual = array_map(
            fn($t) => ['name' => 'my.' . $t['name'], 'value' => $t['value']],
            $expected
        );
        $this->assertSame([], diff_campaign_tokens($expected, $actual));
    }

    // ── diff_campaign_tokens — 불일치 케이스 ──────────────────────

    public function testValueMismatchIsReported(): void
    {
        $expected = build_campaign_tokens([
            'emoji'           => '',
            'email_title'     => 'Hello',
            'email_preheader' => 'Sub',
            'reward_url'      => 'https://example.com',
        ]);
        $actual = [
            ['name' => 'my.Emoji',     'value' => ''],
            ['name' => 'my.Title',     'value' => 'WRONG'], // ← 변조
            ['name' => 'my.Preheader', 'value' => 'Sub'],
            ['name' => 'my.RewardUrl', 'value' => 'https://example.com'],
        ];
        $mismatches = diff_campaign_tokens($expected, $actual);
        $this->assertCount(1, $mismatches);
        $this->assertStringContainsString('Title', $mismatches[0]);
        $this->assertStringContainsString('expected="Hello"', $mismatches[0]);
        $this->assertStringContainsString('actual="WRONG"', $mismatches[0]);
    }

    public function testMissingTokenIsReported(): void
    {
        $expected = build_campaign_tokens([
            'emoji'           => '',
            'email_title'     => 'Hello',
            'email_preheader' => 'Sub',
            'reward_url'      => 'https://example.com',
        ]);
        // RewardUrl 누락
        $actual = [
            ['name' => 'my.Emoji',     'value' => ''],
            ['name' => 'my.Title',     'value' => 'Hello'],
            ['name' => 'my.Preheader', 'value' => 'Sub'],
        ];
        $mismatches = diff_campaign_tokens($expected, $actual);
        $this->assertCount(1, $mismatches);
        $this->assertStringContainsString('RewardUrl', $mismatches[0]);
        $this->assertStringContainsString('missing in Marketo response', $mismatches[0]);
    }

    public function testCaseSensitiveComparison(): void
    {
        // 대소문자가 다르면 불일치로 잡아야 함 (이메일 본문 정확성 보장)
        $expected = build_campaign_tokens([
            'emoji'           => '',
            'email_title'     => 'Hello',
            'email_preheader' => 'Sub',
            'reward_url'      => 'https://example.com',
        ]);
        $actual = [
            ['name' => 'my.Emoji',     'value' => ''],
            ['name' => 'my.Title',     'value' => 'hello'], // ← 소문자
            ['name' => 'my.Preheader', 'value' => 'Sub'],
            ['name' => 'my.RewardUrl', 'value' => 'https://example.com'],
        ];
        $mismatches = diff_campaign_tokens($expected, $actual);
        $this->assertCount(1, $mismatches);
        $this->assertStringContainsString('Title', $mismatches[0]);
    }

    public function testMultipleMismatchesAllReported(): void
    {
        $expected = build_campaign_tokens([
            'emoji'           => '',
            'email_title'     => 'Hello',
            'email_preheader' => 'Sub',
            'reward_url'      => 'https://example.com',
        ]);
        $actual = [
            ['name' => 'my.Emoji',     'value' => 'STALE'],
            ['name' => 'my.Title',     'value' => 'STALE'],
            ['name' => 'my.Preheader', 'value' => 'Sub'],
            ['name' => 'my.RewardUrl', 'value' => 'https://wrong.example.com'],
        ];
        $mismatches = diff_campaign_tokens($expected, $actual);
        $this->assertCount(3, $mismatches);
        // 각 불일치가 해당 키 이름을 포함
        $joined = implode(' || ', $mismatches);
        $this->assertStringContainsString('Emoji', $joined);
        $this->assertStringContainsString('Title', $joined);
        $this->assertStringContainsString('RewardUrl', $joined);
    }

    public function testEmptyExpectedReturnsEmptyDiff(): void
    {
        // 방어적 케이스: expected가 비어있으면 비교할 게 없으므로 빈 배열
        $this->assertSame([], diff_campaign_tokens([], [
            ['name' => 'my.Emoji', 'value' => 'anything'],
        ]));
    }

    public function testExtraActualTokensIgnored(): void
    {
        // Marketo 응답에 추가 토큰(기대값에 없는 것)이 섞여 있어도 무시 — 우리는 4개 키만 본다
        $expected = build_campaign_tokens([
            'emoji'           => '',
            'email_title'     => 'Hello',
            'email_preheader' => 'Sub',
            'reward_url'      => 'https://example.com',
        ]);
        $actual = [
            ['name' => 'my.Emoji',     'value' => ''],
            ['name' => 'my.Title',     'value' => 'Hello'],
            ['name' => 'my.Preheader', 'value' => 'Sub'],
            ['name' => 'my.RewardUrl', 'value' => 'https://example.com'],
            ['name' => 'my.UnrelatedToken', 'value' => 'whatever'],
        ];
        $this->assertSame([], diff_campaign_tokens($expected, $actual));
    }
}
