<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 리드별 cap — 순수(=DB 무관) 정적 메서드 단위 테스트.
 *
 * DB 의존 메서드(computeBlockedEmails / persistHold / attachLeadIds /
 * confirmSent / clearForCampaign / purgeOlderThan / summaryForCampaign)는
 * 운영 통합 검증 영역. 본 파일은 extractSentTargets 등 순수 헬퍼만 다룬다.
 */
final class SendCapTest extends TestCase
{
    // ── extractSentTargets ────────────────────────────────────────

    public function testExtractSentTargetsCollectsLeadIdsFromSendActivities(): void
    {
        $acts = [
            ['activityTypeId' => 6, 'leadId' => 101],
            ['activityTypeId' => 6, 'leadId' => 102],
            ['activityTypeId' => 7, 'leadId' => 103], // delivered — 제외
        ];
        $out = SendCap::extractSentTargets($acts);
        $this->assertSame([101, 102], array_values($out['lead_ids']));
        $this->assertSame([], $out['emails']);
    }

    public function testExtractSentTargetsExtractsEmailFromAttributes(): void
    {
        $acts = [
            [
                'activityTypeId' => 6,
                'leadId' => 0, // leadId 없는 케이스
                'attributes' => [
                    ['name' => 'Step ID', 'value' => '99'],
                    ['name' => 'Recipient', 'value' => 'a@b.com'],
                ],
            ],
            [
                'activityTypeId' => 6,
                'leadId' => 0,
                'attributes' => [
                    ['name' => 'email address', 'value' => 'B@b.com'], // 소문자 정규화 검증
                ],
            ],
        ];
        $out = SendCap::extractSentTargets($acts);
        $this->assertSame([], $out['lead_ids']);
        $this->assertContains('a@b.com', $out['emails']);
        $this->assertContains('b@b.com', $out['emails']);
    }

    public function testExtractSentTargetsIgnoresNonSendTypes(): void
    {
        $acts = [
            ['activityTypeId' => 7,  'leadId' => 1],  // delivered
            ['activityTypeId' => 11, 'leadId' => 2],  // soft bounce
            ['activityTypeId' => 12, 'leadId' => 3],  // hard bounce
            ['activityTypeId' => 10, 'leadId' => 4],  // open
            ['activityTypeId' => 13, 'leadId' => 5],  // click
        ];
        $out = SendCap::extractSentTargets($acts);
        $this->assertSame([], $out['lead_ids']);
        $this->assertSame([], $out['emails']);
    }

    public function testExtractSentTargetsIgnoresInvalidEmailFormat(): void
    {
        $acts = [
            ['activityTypeId' => 6, 'leadId' => 0,
             'attributes' => [['name' => 'Email', 'value' => 'not-an-email']]],
        ];
        $out = SendCap::extractSentTargets($acts);
        $this->assertSame([], $out['emails']);
    }

    public function testExtractSentTargetsDedupesLeadIdsAndEmails(): void
    {
        $acts = [
            ['activityTypeId' => 6, 'leadId' => 101,
             'attributes' => [['name' => 'Recipient', 'value' => 'same@x.com']]],
            ['activityTypeId' => 6, 'leadId' => 101,
             'attributes' => [['name' => 'Recipient', 'value' => 'SAME@x.com']]],
        ];
        $out = SendCap::extractSentTargets($acts);
        $this->assertSame([101], array_values($out['lead_ids']));
        $this->assertSame(['same@x.com'], $out['emails']);
    }

    public function testExtractSentTargetsHandlesEmptyArray(): void
    {
        $out = SendCap::extractSentTargets([]);
        $this->assertSame([], $out['lead_ids']);
        $this->assertSame([], $out['emails']);
    }

    public function testExtractSentTargetsHandlesMissingAttributes(): void
    {
        // attributes 키 자체가 없는 경우 (leadId 만) — leadId 만 박제
        $acts = [
            ['activityTypeId' => 6, 'leadId' => 555],
        ];
        $out = SendCap::extractSentTargets($acts);
        $this->assertSame([555], array_values($out['lead_ids']));
        $this->assertSame([], $out['emails']);
    }

    public function testExtractSentTargetsAcceptsBothLeadIdAndEmailSimultaneously(): void
    {
        // 같은 sent activity 에 leadId 와 email 모두 있어도 두 컬렉션 모두 채워짐
        $acts = [
            ['activityTypeId' => 6, 'leadId' => 77,
             'attributes' => [['name' => 'Email Address', 'value' => 'x@y.com']]],
        ];
        $out = SendCap::extractSentTargets($acts);
        $this->assertSame([77], array_values($out['lead_ids']));
        $this->assertSame(['x@y.com'], $out['emails']);
    }

    public function testExtractSentTargetsIgnoresUnknownAttributeNames(): void
    {
        $acts = [
            ['activityTypeId' => 6, 'leadId' => 0,
             'attributes' => [
                 ['name' => 'Campaign ID', 'value' => 'abc@def.com'], // 이메일처럼 보여도 name 이 캠페인 ID 이면 무시
                 ['name' => 'Choice Number', 'value' => '0'],
             ]],
        ];
        $out = SendCap::extractSentTargets($acts);
        $this->assertSame([], $out['emails']);
    }
}
