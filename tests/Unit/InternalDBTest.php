<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fix 3 — InternalDB::injectQueryTimeoutHint 순수함수 단위 테스트.
 *
 * MAX_EXECUTION_TIME 옵티마이저 힌트는 MySQL 5.7.8+ 에서만 동작하고 MariaDB 는
 * 일반 주석으로 무시한다. 본 테스트는 SQL 변환 결과 자체의 정확성만 검증한다.
 * 실제 timeout 발동은 통합 테스트 영역.
 */
final class InternalDBTest extends TestCase
{
    public function testNoopWhenTimeoutIsZero(): void
    {
        $sql = 'SELECT email FROM users WHERE country = ?';
        $this->assertSame($sql, InternalDB::injectQueryTimeoutHint($sql, 0));
    }

    public function testNoopWhenTimeoutIsNegative(): void
    {
        $sql = 'SELECT email FROM users';
        $this->assertSame($sql, InternalDB::injectQueryTimeoutHint($sql, -1));
    }

    public function testInjectsHintAfterSelectKeyword(): void
    {
        $sql = 'SELECT email FROM users WHERE country = ?';
        $out = InternalDB::injectQueryTimeoutHint($sql, 60000);
        $this->assertStringContainsString('SELECT /*+ MAX_EXECUTION_TIME(60000) */', $out);
        // 원본 SELECT 본문은 보존
        $this->assertStringContainsString('email FROM users WHERE country = ?', $out);
    }

    public function testInjectsOnlyOnceEvenWithSubquery(): void
    {
        // 서브쿼리의 SELECT 까지 침투하면 옵티마이저가 오류 — 첫 번째 SELECT 만 변환.
        $sql = 'SELECT email FROM users WHERE id IN (SELECT user_id FROM marketing_consents)';
        $out = InternalDB::injectQueryTimeoutHint($sql, 30000);
        $this->assertSame(1, substr_count($out, '/*+ MAX_EXECUTION_TIME'));
    }

    public function testDoesNotDoubleInject(): void
    {
        // 이미 힌트가 박힌 SQL 은 그대로 — 이중 주입 방지.
        $sql = 'SELECT /*+ MAX_EXECUTION_TIME(30000) */ email FROM users';
        $this->assertSame($sql, InternalDB::injectQueryTimeoutHint($sql, 60000));
    }

    public function testCaseInsensitiveSelectKeyword(): void
    {
        $sql = 'select email from users';
        $out = InternalDB::injectQueryTimeoutHint($sql, 60000);
        $this->assertStringContainsString('/*+ MAX_EXECUTION_TIME(60000) */', $out);
    }

    public function testPreservesParamsStructure(): void
    {
        // bindParam 위치(?)에 영향 없음 — placeholder 순서 보존
        $sql = 'SELECT email FROM users WHERE is_active = ? AND country = ?';
        $out = InternalDB::injectQueryTimeoutHint($sql, 60000);
        $this->assertSame(2, substr_count($out, '?'));
    }

    public function testHintIsValidMysqlOptimizerHintFormat(): void
    {
        // 형식: SELECT /*+ MAX_EXECUTION_TIME(N) */ ...
        // - MySQL 5.7.8+ 가 인식하는 정확한 형태
        // - MariaDB 는 `/*+ ... */` 를 일반 multi-line 주석으로 보고 무시
        $out = InternalDB::injectQueryTimeoutHint('SELECT 1', 12345);
        $this->assertMatchesRegularExpression('/SELECT\s+\/\*\+\s*MAX_EXECUTION_TIME\(12345\)\s*\*\//', $out);
    }
}
