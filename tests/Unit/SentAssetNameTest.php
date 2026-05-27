<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SEV1 RCA(2026-05-22) 후속 — extractSentEmailAssetNames 순수함수 검증.
 *
 * 본 헬퍼는 Marketo Activity API 페이로드에서 sent(typeId=6) 의
 * primaryAttributeValue 로 *실제 발송된 이메일 자산 이름* 을 모은다.
 * cron 이 그 결과를 campaigns.asset_name(=운영자 의도) 과 비교 → 불일치 시
 * needs_manual_review 격리 + Slack 'crit'. 같은 SEV1 사고(다른 promo 가 운영자 의도
 * 대신 발송된 케이스) 재발 즉시 감지.
 */
final class SentAssetNameTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../src/Marketo/MarketoAPI.php';
    }

    public function testExtractsSingleAssetNameFromSendActivity(): void
    {
        $acts = [
            ['activityTypeId' => 6, 'leadId' => 100, 'primaryAttributeValue' => 'Smash The Piggy'],
        ];
        $this->assertSame(['Smash The Piggy'], MarketoAPI::extractSentEmailAssetNames($acts));
    }

    public function testIgnoresNonSendActivities(): void
    {
        $acts = [
            ['activityTypeId' => 7,  'primaryAttributeValue' => 'Delivered Asset Name'],
            ['activityTypeId' => 11, 'primaryAttributeValue' => 'Soft Bounce Asset'],
            ['activityTypeId' => 10, 'primaryAttributeValue' => 'Open Asset'],
        ];
        $this->assertSame([], MarketoAPI::extractSentEmailAssetNames($acts));
    }

    public function testDedupesIdenticalAssetNames(): void
    {
        // 같은 자산이 1,000명에게 발송돼도 1개 이름으로 합쳐짐
        $acts = array_fill(0, 1000, ['activityTypeId' => 6, 'primaryAttributeValue' => 'Smash The Piggy']);
        $out = MarketoAPI::extractSentEmailAssetNames($acts);
        $this->assertCount(1, $out);
        $this->assertSame('Smash The Piggy', $out[0]);
    }

    public function testDetectsMultipleAssetsInSameCampaign(): void
    {
        // SEV1 사고처럼 의도와 다른 자산이 섞여 발송된 케이스 — 양쪽 모두 반환
        $acts = [
            ['activityTypeId' => 6, 'primaryAttributeValue' => 'Smash The Piggy'],
            ['activityTypeId' => 6, 'primaryAttributeValue' => 'The Clock Keeps Moving'],
        ];
        $out = MarketoAPI::extractSentEmailAssetNames($acts);
        $this->assertContains('Smash The Piggy', $out);
        $this->assertContains('The Clock Keeps Moving', $out);
        $this->assertCount(2, $out);
    }

    public function testIgnoresMissingPrimaryAttributeValue(): void
    {
        $acts = [
            ['activityTypeId' => 6, 'leadId' => 1], // primaryAttributeValue 없음
            ['activityTypeId' => 6, 'leadId' => 2, 'primaryAttributeValue' => ''],
            ['activityTypeId' => 6, 'leadId' => 3, 'primaryAttributeValue' => '   '], // whitespace
        ];
        $this->assertSame([], MarketoAPI::extractSentEmailAssetNames($acts));
    }

    public function testHandlesEmptyArray(): void
    {
        $this->assertSame([], MarketoAPI::extractSentEmailAssetNames([]));
    }

    public function testPreservesCaseAndUnicode(): void
    {
        $acts = [
            ['activityTypeId' => 6, 'primaryAttributeValue' => '한국어 자산 ABC'],
            ['activityTypeId' => 6, 'primaryAttributeValue' => 'MixedCase'],
        ];
        $out = MarketoAPI::extractSentEmailAssetNames($acts);
        $this->assertContains('한국어 자산 ABC', $out);
        $this->assertContains('MixedCase', $out);
    }

    public function testTrimsWhitespace(): void
    {
        $acts = [
            ['activityTypeId' => 6, 'primaryAttributeValue' => '  Asset With Spaces  '],
        ];
        $this->assertSame(['Asset With Spaces'], MarketoAPI::extractSentEmailAssetNames($acts));
    }

    // ── detectAssetNameMismatch (Codex review — mixed 케이스 우회 차단) ──

    public function testDetectMismatchSingleIntendedAssetIsNotMismatch(): void
    {
        $r = MarketoAPI::detectAssetNameMismatch(['Smash The Piggy'], 'Smash The Piggy');
        $this->assertFalse($r['mismatch']);
        $this->assertSame([], $r['unexpected']);
    }

    public function testDetectMismatchSingleWrongAssetIsMismatch(): void
    {
        $r = MarketoAPI::detectAssetNameMismatch(['The Clock Keeps Moving'], 'Smash The Piggy');
        $this->assertTrue($r['mismatch']);
        $this->assertSame(['The Clock Keeps Moving'], $r['unexpected']);
    }

    public function testDetectMismatchMixedAssetsAreMismatch(): void
    {
        // Codex 가 지적한 핵심 — 의도 자산이 *포함되어 있어도* 다른 자산이 섞이면 격리.
        $r = MarketoAPI::detectAssetNameMismatch(
            ['Smash The Piggy', 'The Clock Keeps Moving'],
            'Smash The Piggy'
        );
        $this->assertTrue($r['mismatch'], 'mixed 자산 발송은 mismatch 로 격리되어야 함');
        $this->assertSame(['The Clock Keeps Moving'], $r['unexpected']);
    }

    public function testDetectMismatchMultipleUnexpectedAssets(): void
    {
        $r = MarketoAPI::detectAssetNameMismatch(
            ['Smash The Piggy', 'Asset A', 'Asset B'],
            'Smash The Piggy'
        );
        $this->assertTrue($r['mismatch']);
        $this->assertEqualsCanonicalizing(['Asset A', 'Asset B'], $r['unexpected']);
    }

    public function testDetectMismatchEmptySentReturnsNonMismatch(): void
    {
        // 아직 sent activity 안 들어온 케이스 — 정책상 non-mismatch.
        $r = MarketoAPI::detectAssetNameMismatch([], 'Smash The Piggy');
        $this->assertFalse($r['mismatch']);
        $this->assertSame([], $r['unexpected']);
    }

    public function testDetectMismatchEmptyExpectedReturnsNonMismatch(): void
    {
        // asset_name 미설정 캠페인 — 검증 자체 skip.
        $r = MarketoAPI::detectAssetNameMismatch(['Whatever'], '');
        $this->assertFalse($r['mismatch']);
        $r2 = MarketoAPI::detectAssetNameMismatch(['Whatever'], '   ');
        $this->assertFalse($r2['mismatch']);
    }

    public function testDetectMismatchCaseSensitiveAndExact(): void
    {
        // 대소문자 다르면 mismatch — Marketo asset name 은 case-sensitive 가정.
        $r = MarketoAPI::detectAssetNameMismatch(['smash the piggy'], 'Smash The Piggy');
        $this->assertTrue($r['mismatch']);
        $this->assertSame(['smash the piggy'], $r['unexpected']);
    }

    // ── extractSentEmailAssetNamesForCampaign (M-asset-mismatch 보강) ──

    public function testForCampaignFiltersByCampaignIdAttribute(): void
    {
        // sibling 캠페인(다른 SC ID)의 sent 가 같은 listId 윈도우에 섞여 있어도 본 캠페인만 카운트.
        $acts = [
            // 본 캠페인 (SC ID 7777)
            [
                'activityTypeId' => 6,
                'primaryAttributeValue' => 'Smash The Piggy',
                'attributes' => [['name' => 'Campaign ID', 'value' => 7777]],
            ],
            // sibling 캠페인 (다른 SC ID 9999) — 같은 listId, 24h 윈도우 안. 격리 false-positive 후보.
            [
                'activityTypeId' => 6,
                'primaryAttributeValue' => 'The Clock Keeps Moving',
                'attributes' => [['name' => 'Campaign ID', 'value' => 9999]],
            ],
        ];
        $out = MarketoAPI::extractSentEmailAssetNamesForCampaign($acts, 7777);
        $this->assertSame(['Smash The Piggy'], $out);
    }

    public function testForCampaignAcceptsAlternativeAttributeNames(): void
    {
        // 인스턴스에 따라 'Mailing ID' / 'SC ID' / 'Smart Campaign ID' 등 변종 attribute 이름 사용.
        $acts = [
            ['activityTypeId' => 6, 'primaryAttributeValue' => 'A',
             'attributes' => [['name' => 'Mailing ID', 'value' => 7777]]],
            ['activityTypeId' => 6, 'primaryAttributeValue' => 'B',
             'attributes' => [['name' => 'SC ID', 'value' => 9999]]],
        ];
        $out = MarketoAPI::extractSentEmailAssetNamesForCampaign($acts, 7777);
        $this->assertSame(['A'], $out);
    }

    public function testForCampaignFallsBackToAllWhenNoMatchingAttributesPresent(): void
    {
        // Marketo 인스턴스가 Campaign ID 류 attribute 를 안 돌려주면 *전체 윈도우 폴백*.
        // false-negative (격리 누락) 보다 false-positive (격리됨) 가 운영 안전.
        $acts = [
            ['activityTypeId' => 6, 'primaryAttributeValue' => 'X',
             'attributes' => [['name' => 'Step ID', 'value' => 1]]], // Campaign ID 류 없음
            ['activityTypeId' => 6, 'primaryAttributeValue' => 'Y',
             'attributes' => [['name' => 'Some Other', 'value' => 'whatever']]],
        ];
        $out = MarketoAPI::extractSentEmailAssetNamesForCampaign($acts, 7777);
        $this->assertEqualsCanonicalizing(['X', 'Y'], $out, 'attribute 매칭 시도 자체가 없으면 전체 윈도우 폴백');
    }

    public function testForCampaignWithCampaignIdZeroDisablesFilter(): void
    {
        // campaign_marketo_id <= 0 (e.g. cancel 후 NULL 화) 이면 필터 비활성 — 전체 윈도우 그대로.
        $acts = [
            ['activityTypeId' => 6, 'primaryAttributeValue' => 'A',
             'attributes' => [['name' => 'Campaign ID', 'value' => 7777]]],
        ];
        $this->assertSame(['A'], MarketoAPI::extractSentEmailAssetNamesForCampaign($acts, 0));
        $this->assertSame(['A'], MarketoAPI::extractSentEmailAssetNamesForCampaign($acts, -1));
    }

    public function testForCampaignWithAttributeButNoMatchingValueReturnsEmpty(): void
    {
        // attribute 자체는 있지만 본 캠페인 ID 와 일치 안 함 → 빈 결과 (격리 trigger 안 됨).
        $acts = [
            ['activityTypeId' => 6, 'primaryAttributeValue' => 'Sibling Asset',
             'attributes' => [['name' => 'Campaign ID', 'value' => 9999]]],
        ];
        $this->assertSame([], MarketoAPI::extractSentEmailAssetNamesForCampaign($acts, 7777));
    }
}
