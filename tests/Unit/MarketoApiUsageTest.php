<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PR-4 (δ) — MarketoApiUsage::classifyEndpoint 순수 함수 단위 테스트.
 * record() / getDailySummary() 는 DB 의존이라 통합 테스트 영역.
 */
final class MarketoApiUsageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../src/Marketo/MarketoApiUsage.php';
    }

    public function testAuthToken(): void
    {
        $this->assertSame(
            'auth.token',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/identity/oauth/token?grant_type=client_credentials', 'GET')
        );
    }

    public function testBulkImport(): void
    {
        $this->assertSame(
            'bulk.import',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/bulk/v1/leads.json?format=csv&listId=8293', 'POST')
        );
    }

    public function testBulkStatus(): void
    {
        $this->assertSame(
            'bulk.status',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/bulk/v1/leads/batch/abc-123/status.json', 'GET')
        );
    }

    public function testActivities(): void
    {
        $this->assertSame(
            'activities',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/v1/activities.json?activityTypeIds=6,7&listId=8293&nextPageToken=ABC', 'GET')
        );
    }

    public function testActivitiesPagingToken(): void
    {
        $this->assertSame(
            'activities.pagingToken',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/v1/activities/pagingtoken.json?sinceDatetime=2026-05-21T00:00:00Z', 'GET')
        );
    }

    public function testListsAddLeads(): void
    {
        // POST = 멤버 추가
        $this->assertSame(
            'lists.addLeads',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/v1/lists/8293/leads.json', 'POST')
        );
    }

    public function testListsRemoveLeadsViaMethodOverride(): void
    {
        // 코드가 DELETE 를 ?_method=DELETE 쿼리로 전송하는 패턴
        $this->assertSame(
            'lists.removeLeads',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/v1/lists/8293/leads.json?_method=DELETE', 'DELETE')
        );
    }

    public function testListsListLeads(): void
    {
        // GET = 멤버 조회 (페이징)
        $this->assertSame(
            'lists.listLeads',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/v1/lists/8293/leads.json?fields=id&batchSize=300', 'GET')
        );
    }

    public function testLeadsUpsert(): void
    {
        $this->assertSame(
            'leads.upsert',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/v1/leads.json', 'POST')
        );
    }

    public function testCampaignSchedule(): void
    {
        $this->assertSame(
            'campaigns.schedule',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/v1/campaigns/7610/schedule.json', 'POST')
        );
    }

    public function testEmailProgramSchedule(): void
    {
        $this->assertSame(
            'emailProgram.schedule',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/asset/v1/emailProgram/7309/schedule.json', 'POST')
        );
    }

    public function testEmailProgramUnapprove(): void
    {
        $this->assertSame(
            'emailProgram.unapprove',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/asset/v1/emailProgram/7309/unapprove.json', 'POST')
        );
    }

    public function testEmailSendSample(): void
    {
        $this->assertSame(
            'email.sendSample',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/asset/v1/email/12345/sendSample.json?emailAddress=ops@x.com', 'POST')
        );
    }

    public function testFolderTokensSet(): void
    {
        $this->assertSame(
            'folder.tokens.set',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/asset/v1/folder/100/tokens.json', 'POST')
        );
    }

    public function testFolderTokenClear(): void
    {
        $this->assertSame(
            'folder.tokens.clear',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/asset/v1/folder/100/token.json', 'DELETE')
        );
    }

    public function testProgramTokens(): void
    {
        $this->assertSame(
            'program.tokens',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/asset/v1/program/7309/tokens.json', 'GET')
        );
    }

    public function testProgramsList(): void
    {
        $this->assertSame(
            'programs.list',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/asset/v1/programs.json?maxReturn=200&offset=0', 'GET')
        );
    }

    public function testStaticLists(): void
    {
        $this->assertSame(
            'staticLists.list',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/asset/v1/staticLists.json?maxReturn=200', 'GET')
        );
    }

    public function testUnknownPathFallsBackToOther(): void
    {
        $this->assertSame(
            'other',
            MarketoApiUsage::classifyEndpoint('https://acme.mktorest.com/rest/v999/mystery.json', 'GET')
        );
    }

    public function testInvalidUrlFallsBackToOther(): void
    {
        $this->assertSame('other', MarketoApiUsage::classifyEndpoint('', 'GET'));
        $this->assertSame('other', MarketoApiUsage::classifyEndpoint('not a url', 'GET'));
    }

    /**
     * VARCHAR(100) 제약 — 모든 카테고리 키가 100자 이내인지 검증.
     */
    public function testAllEndpointKeysFitInVarchar100(): void
    {
        $samples = [
            ['url' => 'https://x.mktorest.com/identity/oauth/token', 'method' => 'GET'],
            ['url' => 'https://x.mktorest.com/rest/v1/lists/1/leads.json', 'method' => 'POST'],
            ['url' => 'https://x.mktorest.com/rest/asset/v1/emailProgram/1/schedule.json', 'method' => 'POST'],
        ];
        foreach ($samples as $s) {
            $key = MarketoApiUsage::classifyEndpoint($s['url'], $s['method']);
            $this->assertLessThanOrEqual(100, strlen($key), "endpoint key '$key' exceeds 100 chars");
        }
    }
}
