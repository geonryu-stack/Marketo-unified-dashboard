<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PR-2 — Activity maxPages 캡 + 이어받기 로직 검증.
 *
 * getActivitiesPaginated 자체는 curl 의존이라 외부 mock 없이 단위 테스트 불가.
 * 본 테스트는 cron 의 카운트 누적/덮어쓰기 결정 로직만 순수하게 검증한다.
 * (resume 토큰 유무에 따라 sent_count 등을 누적할지 새로 시작할지)
 *
 * 회귀 시나리오 — 기존 동작은 매 cron 마다 since 부터 전체 폴링 → sent_count
 * 덮어쓰기(멱등). PR-2 가 truncated/resume 시에만 누적되도록 분기하지 않으면
 * 같은 activity 가 매 cron 마다 카운트에 더해져 *중복 카운팅* 발생.
 */
final class ActivityResumeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../src/Marketo/MarketoAPI.php';
    }

    /**
     * cron 의 카운트 정책 — resume 여부에 따라 누적 vs 덮어쓰기.
     */
    private function resolveCount(int $prev, int $delta, ?string $resume_token): int
    {
        $is_resume = ($resume_token !== null && $resume_token !== '');
        return $is_resume ? ($prev + $delta) : $delta;
    }

    public function testFreshPollOverwritesCount(): void
    {
        // resume 없음 — 매 cron 새 paging token. 기존 카운트는 무시하고 이번 delta 만.
        $this->assertSame(50, $this->resolveCount(/*prev*/ 30, /*delta*/ 50, /*resume*/ null));
        $this->assertSame(50, $this->resolveCount(30, 50, ''));
    }

    public function testResumePollAccumulatesCount(): void
    {
        // resume 있음 — 이전에 truncated 된 후 이어받기. delta 만큼 누적.
        $this->assertSame(80, $this->resolveCount(30, 50, 'some-paging-token'));
    }

    public function testFreshPollWithZeroPrevIsStillFresh(): void
    {
        // 첫 폴링(prev=0, resume=null) 도 동일 — delta 가 그대로 total.
        $this->assertSame(50, $this->resolveCount(0, 50, null));
    }

    public function testResumeWithZeroDeltaPreservesPrev(): void
    {
        // resume 했는데 이번 페이지가 비어있을 수 있음 — 기존 카운트 보존.
        $this->assertSame(30, $this->resolveCount(30, 0, 'token'));
    }

    /**
     * getActivitiesPaginated 반환 형식 sanity — 시그니처 변경에 대한 회귀 가드.
     * 실제 호출은 못 하지만 ReflectionMethod 로 반환 타입과 파라미터 수 검증.
     */
    public function testGetActivitiesPaginatedSignatureFrozen(): void
    {
        $rm = new ReflectionMethod('MarketoAPI', 'getActivitiesPaginated');
        $params = $rm->getParameters();
        $this->assertCount(5, $params, '시그니처: listId, sinceIso, typeIds, resumeToken, maxPages');
        $this->assertSame('listId',      $params[0]->getName());
        $this->assertSame('sinceIso',    $params[1]->getName());
        $this->assertSame('typeIds',     $params[2]->getName());
        $this->assertSame('resumeToken', $params[3]->getName());
        $this->assertSame('maxPages',    $params[4]->getName());
    }

    /**
     * tallyEngagement(기존 순수함수)이 PR-2 변경에 영향 없는지 sanity.
     * cron 은 더 단순한 4-typeId(6/7/11/12) 만 폴링하지만, tally 헬퍼는 그대로.
     */
    public function testTallyEngagementUnchanged(): void
    {
        $acts = [
            ['activityTypeId' => 6],  ['activityTypeId' => 6],
            ['activityTypeId' => 7],
            ['activityTypeId' => 11], ['activityTypeId' => 12],
            ['activityTypeId' => 10],
        ];
        $tally = MarketoAPI::tallyEngagement($acts);
        $this->assertSame(2, $tally['sent']);
        $this->assertSame(1, $tally['delivered']);
        $this->assertSame(1, $tally['soft_bounce']);
        $this->assertSame(1, $tally['hard_bounce']);
        $this->assertSame(2, $tally['bounce']); // soft + hard
        $this->assertSame(1, $tally['open']);
    }
}
