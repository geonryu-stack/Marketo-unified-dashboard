<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sprint 3 MKT ⑭ — MarketoBulkImport::computeProgress() 의 단위 테스트.
 *
 * 순수 함수이므로 외부 호출 없이 입력만 변주해 다음 6가지 케이스를 검증:
 *   1) 정상 진행 중 (50% + 적정 rows/sec + 유효 ETA)
 *   2) 시작 직후 (elapsed=0 → rows_per_sec=null)
 *   3) 완료 상태 (progress=100, eta=0)
 *   4) 0% (방금 시작, processed=0)
 *   5) total=0 (Marketo 미제공) → pct=0, eta=null
 *   6) 키 fallback (numOfRowsCompleted / numOfRows)
 */
final class BulkProgressTest extends TestCase
{
    /**
     * MarketoBulkImport 로드.
     * MarketoAPI에 의존하지만(require_once) 정적 메서드만 사용하는 헬퍼는 클래스 로드만으로 충분.
     */
    public static function setUpBeforeClass(): void
    {
        // src/Marketo/MarketoAPI.php 와 MarketoBulkImport.php 를 끌어온다.
        // 둘 다 require_once 가드가 있으므로 중복 로드 안전.
        if (!class_exists('MarketoBulkImport')) {
            // MarketoAPI.php 가 config 의존이 있어도 require_once 자체는 클래스 정의만 함.
            // (token fetch는 호출 시점에만 일어남 — 본 테스트는 호출하지 않음)
            require_once __DIR__ . '/../../src/Marketo/MarketoAPI.php';
            require_once __DIR__ . '/../../src/Marketo/MarketoBulkImport.php';
        }
    }

    // ── 1) 정상 진행 중 (50%) ──────────────────────────────────────
    public function testHalfwayProgressYieldsValidRateAndEta(): void
    {
        // started_at = 100초 전, processed=5000 / total=10000 → 50%, 50 rows/sec, eta≈100s
        $started_at = date('Y-m-d H:i:s', time() - 100);
        $result = MarketoBulkImport::computeProgress([
            'status'              => 'Importing',
            'numOfRowsProcessed'  => 5000,
            'numOfRowsTotal'      => 10000,
            'numOfRowsFailed'     => 0,
        ], $started_at);

        $this->assertSame('Importing', $result['status']);
        $this->assertSame(5000, $result['processed']);
        $this->assertSame(10000, $result['total']);
        $this->assertSame(0, $result['failed']);
        $this->assertEqualsWithDelta(50.0, $result['progress_pct'], 0.01);
        // 정확히 100초 흘렀다고 단정할 수 없으므로(테스트 실행시간 ±) 합리 범위 검증.
        $this->assertNotNull($result['rows_per_sec']);
        $this->assertGreaterThan(40.0, $result['rows_per_sec']);
        $this->assertLessThan(100.0, $result['rows_per_sec']);
        $this->assertNotNull($result['eta_sec']);
        $this->assertGreaterThan(50, $result['eta_sec']);
        $this->assertLessThan(200, $result['eta_sec']);
        $this->assertNotNull($result['elapsed_sec']);
        $this->assertGreaterThanOrEqual(99, $result['elapsed_sec']);
    }

    // ── 2) 시작 직후 (elapsed=0) ──────────────────────────────────
    public function testJustStartedYieldsNullRate(): void
    {
        // started_at = 미래 시각 → elapsed=0(clamp), rows_per_sec=null
        $started_at = date('Y-m-d H:i:s', time() + 5);
        $result = MarketoBulkImport::computeProgress([
            'status'              => 'Queued',
            'numOfRowsProcessed'  => 0,
            'numOfRowsTotal'      => 10000,
        ], $started_at);

        $this->assertSame('Queued', $result['status']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(0.0, $result['progress_pct']);
        $this->assertNull($result['rows_per_sec'], '방금 시작 — rate 산출 불가');
        $this->assertNull($result['eta_sec'], '방금 시작 — ETA 산출 불가');
        $this->assertSame(0, $result['elapsed_sec']);
    }

    // ── 3) 완료 상태 ──────────────────────────────────────────────
    public function testCompleteStatusYieldsHundredPctAndZeroEta(): void
    {
        $started_at = date('Y-m-d H:i:s', time() - 60);
        $result = MarketoBulkImport::computeProgress([
            'status'              => 'Complete',
            'numOfRowsProcessed'  => 10000,
            'numOfRowsTotal'      => 10000,
            'numOfRowsFailed'     => 0,
        ], $started_at);

        $this->assertSame('Complete', $result['status']);
        $this->assertEqualsWithDelta(100.0, $result['progress_pct'], 0.01);
        $this->assertSame(0, $result['eta_sec'], '완료 — 남은 시간 0');
        $this->assertSame(0, $result['failed']);
        $this->assertNotNull($result['rows_per_sec']);
    }

    // ── 4) 0% (방금 시작, processed=0) ────────────────────────────
    public function testZeroProcessedYieldsZeroPctAndNullRate(): void
    {
        $started_at = date('Y-m-d H:i:s', time() - 10);
        $result = MarketoBulkImport::computeProgress([
            'status'              => 'Importing',
            'numOfRowsProcessed'  => 0,
            'numOfRowsTotal'      => 10000,
        ], $started_at);

        $this->assertSame(0.0, $result['progress_pct']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(10000, $result['total']);
        $this->assertNull($result['rows_per_sec'], 'processed=0 — rate 산출 불가');
        $this->assertNull($result['eta_sec'], 'rate 없으면 ETA 없음');
        $this->assertGreaterThanOrEqual(9, $result['elapsed_sec']);
    }

    // ── 5) total=0 (Marketo가 total 안 주는 케이스) ───────────────
    public function testZeroTotalYieldsZeroPctAndNullEta(): void
    {
        $started_at = date('Y-m-d H:i:s', time() - 30);
        $result = MarketoBulkImport::computeProgress([
            'status'              => 'Importing',
            'numOfRowsProcessed'  => 150,
            // numOfRowsTotal 키 부재
            'numOfRowsFailed'     => 0,
        ], $started_at);

        $this->assertSame(0, $result['total']);
        $this->assertSame(0.0, $result['progress_pct'], 'total=0 — pct 산출 불가');
        $this->assertNull($result['eta_sec'], 'total=0 — ETA 산출 불가');
        // rate는 산출 가능 (processed>0 && elapsed>0)
        $this->assertNotNull($result['rows_per_sec']);
        $this->assertGreaterThan(0.0, $result['rows_per_sec']);
    }

    // ── 6) 키 fallback (numOfRowsCompleted / numOfRows) ───────────
    public function testFallbackKeys(): void
    {
        $started_at = date('Y-m-d H:i:s', time() - 20);
        $result = MarketoBulkImport::computeProgress([
            'status'              => 'Importing',
            // numOfRowsProcessed 대신 numOfRowsCompleted
            'numOfRowsCompleted'  => 200,
            // numOfRowsTotal 대신 numOfRows
            'numOfRows'           => 1000,
        ], $started_at);

        $this->assertSame(200, $result['processed']);
        $this->assertSame(1000, $result['total']);
        $this->assertEqualsWithDelta(20.0, $result['progress_pct'], 0.01);
    }

    // ── 보너스) started_at null — elapsed/rate/eta 모두 null ─────
    public function testNullStartedAtYieldsAllTimingsNull(): void
    {
        $result = MarketoBulkImport::computeProgress([
            'status'              => 'Importing',
            'numOfRowsProcessed'  => 500,
            'numOfRowsTotal'      => 1000,
        ], null);

        $this->assertEqualsWithDelta(50.0, $result['progress_pct'], 0.01);
        $this->assertNull($result['elapsed_sec']);
        $this->assertNull($result['rows_per_sec']);
        $this->assertNull($result['eta_sec']);
    }
}
