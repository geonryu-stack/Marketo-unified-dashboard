<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SendCap.php 의 SQL 안티패턴 회귀 가드 (코드리뷰어 H-2 보강).
 *
 * 이상적으로는 SQLite 인메모리 통합 테스트가 SQL semantic 회귀까지 잡지만, 본 프로젝트는
 * MySQL 전용 문법(`ON DUPLICATE KEY UPDATE`, `SUM(send_date = ?)` implicit boolean cast,
 * batch CASE WHEN) 을 사용해 SQLite 와 호환 불가. DB 통합 테스트는 핸드오프 후 외부 개발자가
 * XAMPP MySQL + 분리된 test DB schema 로 구축할 것을 권장 (HANDOFF.md 항목으로 명시).
 *
 * 본 정적 가드는 *흔한 회귀 안티패턴* 만 차단:
 *  - priority 비교가 >= 에서 > 로 회귀 (같은 priority 점유 안 카운트)
 *  - hold 만 제외해야 할 SQL 이 sent 까지 제외하도록 회귀
 *  - persistHold 의 멱등성 (ON DUPLICATE KEY) 제거
 *  - clearForCampaign 이 sent 까지 지우도록 회귀
 *  - confirmSent 가 state='sent' 가 아닌 다른 값으로 회귀
 */
final class SendCapSqlGuardTest extends TestCase
{
    private static string $src;

    public static function setUpBeforeClass(): void
    {
        self::$src = (string)file_get_contents(__DIR__ . '/../../src/SendCap.php');
    }

    /**
     * H-2 회귀 — priority 비교는 *>=* 여야 함. > 로 회귀 시 본 세그먼트와 같은 priority 의
     * 점유가 cap 카운트에 반영되지 않음 → 의도하지 않은 중복 발송.
     */
    public function testComputeBlockedEmailsUsesGreaterOrEqualForPriority(): void
    {
        $this->assertMatchesRegularExpression(
            '/priority\s*>=\s*\?/',
            self::$src,
            'computeBlockedEmails 의 priority 비교가 사라짐'
        );
        // > ? 단독 사용 차단 — >= 의 일부로 나타나는 것은 OK
        $this->assertDoesNotMatchRegularExpression(
            '/priority\s*>\s*\?[^=]/',
            self::$src,
            'priority > ? 단독 사용 회귀 → 같은 priority 점유가 카운트 안 됨'
        );
    }

    /**
     * H-2 회귀 — 본인 캠페인 제외 분기는 *hold 만* 빼야 함. sent 까지 빼면 본인 발송 사실이
     * cap 윈도우에서 사라져 같은 캠페인 재추출 시 동일 이메일에 두 번 발송 위험.
     */
    public function testComputeBlockedEmailsExcludesOnlyHoldNotSent(): void
    {
        $this->assertStringContainsString(
            "NOT (campaign_id = ? AND state = 'hold')",
            self::$src,
            "자기-제외 분기가 hold-only 가 아닌 형태로 회귀 — 본인 sent 가 윈도우에서 사라지면 재추출 시 중복 발송 위험"
        );
    }

    /**
     * H-2 회귀 — persistHold 는 멱등이어야 함. ON DUPLICATE KEY UPDATE 가 사라지면
     * 재추출 시 PRIMARY KEY 위반으로 throw → 추출 자체 실패.
     */
    public function testPersistHoldIsIdempotentViaOnDuplicateKey(): void
    {
        $this->assertStringContainsString(
            'ON DUPLICATE KEY UPDATE',
            self::$src,
            'persistHold 멱등성 제거 회귀 — 재추출 시 PRIMARY KEY 충돌로 throw'
        );
    }

    /**
     * H-2 회귀 — clearForCampaign 은 *hold 만* 지워야 함. sent 까지 지우면 cancel 후
     * 같은 윈도우에 다른 캠페인이 동일 이메일에 또 발송 (cap 위반).
     */
    public function testClearForCampaignPreservesSent(): void
    {
        $this->assertMatchesRegularExpression(
            "/DELETE FROM lead_send_history WHERE campaign_id=\?\s+AND state='hold'/",
            self::$src,
            'clearForCampaign 이 sent 도 지우도록 회귀 → cap 윈도우 보존 의도 파괴'
        );
    }

    /**
     * H-2 회귀 — confirmSent 는 state='sent' 로 박제해야 함. 다른 값으로 회귀 시
     * cap 윈도우 조회에서 누락 → 중복 발송 위험.
     */
    public function testConfirmSentSetsStateToSent(): void
    {
        $this->assertMatchesRegularExpression(
            "/SET\s+state='sent'/",
            self::$src,
            "confirmSent 가 state='sent' 박제하지 않음 → Marketo 발송 사실이 cap 윈도우에 반영 안 됨"
        );
    }

    /**
     * H-2 회귀 — attachLeadIds 는 *동일 캠페인 범위* 안에서만 UPDATE 해야 함.
     * WHERE campaign_id=? 가 사라지면 다른 캠페인의 같은 email 의 lead_id 까지 덮어쓸 위험.
     */
    public function testAttachLeadIdsScopedToCampaign(): void
    {
        // attachLeadIds 메서드 본문 추출
        $pattern = '/public static function attachLeadIds\([^)]*\)[^{]*\{.*?\n    \}/s';
        $this->assertSame(1, preg_match($pattern, self::$src, $m), 'attachLeadIds 메서드 본문 못 찾음');
        $body = $m[0];

        $this->assertStringContainsString(
            'WHERE campaign_id = ?',
            $body,
            'attachLeadIds UPDATE 가 campaign_id 로 scoped 되지 않으면 다른 캠페인 행 오염 위험'
        );
    }

    /**
     * H-2 회귀 — purgeOlderThan 의 안전 하한이 사라지지 않게. 7일 미만 입력은 7일로
     * floor 처리. 운영자가 실수로 1일 입력해도 cap 윈도우 안의 박제 행이 삭제되지 않게.
     */
    public function testPurgeHasSafetyFloor(): void
    {
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$days\s*<\s*7\s*\)/',
            self::$src,
            'purgeOlderThan 의 7일 안전 하한 가드가 제거됨 → 운영자 실수로 윈도우 안 행 삭제 위험'
        );
    }
}
