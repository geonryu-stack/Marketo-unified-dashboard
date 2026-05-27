<?php
// src/InternalDB.php
// 사내 백업 DB 읽기 전용 어댑터.
//
// 타임아웃 정책 (Fix 3 — DBA 개정안):
//  - ATTR_TIMEOUT: TCP connect/auth 단계 한도. mysqlnd 에서 MYSQL_OPT_CONNECT_TIMEOUT
//                  으로 매핑. **쿼리 실행 중에는 효과 없음** (흔한 오해).
//  - 쿼리 timeout: SELECT 앞단에 옵티마이저 힌트 `/*+ MAX_EXECUTION_TIME(ms) */` 주입.
//                  MySQL 5.7.8+ 에서만 동작하며 MariaDB 는 일반 주석으로 무시한다.
//                  INIT_COMMAND 방식은 MariaDB 에서 connection 자체를 실패시키므로 회피.
//  - PHP ini `max_execution_time` 은 Windows 환경에서 DB wait 중 카운트가 멈출 수
//                  있어 단독으로는 hang 차단 보장 불가. DB 측 timeout 이 진짜 안전망.
declare(strict_types=1);

class InternalDB
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                INTERNAL_DB_HOST, INTERNAL_DB_PORT, INTERNAL_DB_NAME
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $connect_timeout = defined('INTERNAL_DB_CONNECT_TIMEOUT_SEC')
                ? (int)INTERNAL_DB_CONNECT_TIMEOUT_SEC : 0;
            if ($connect_timeout > 0) {
                $options[PDO::ATTR_TIMEOUT] = $connect_timeout;
            }
            self::$instance = new PDO($dsn, INTERNAL_DB_USER, INTERNAL_DB_PASS, $options);
        }
        return self::$instance;
    }

    /** SELECT 전용 실행 — CONSTRAINT-01 */
    public static function query(string $sql, array $params = []): array
    {
        assert_readonly($sql); // helpers.php — SELECT 외 키워드 차단
        $timeout_ms = defined('INTERNAL_DB_QUERY_TIMEOUT_MS')
            ? (int)INTERNAL_DB_QUERY_TIMEOUT_MS : 0;
        $sql  = self::injectQueryTimeoutHint($sql, $timeout_ms);
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * SELECT 문 앞단에 MAX_EXECUTION_TIME 옵티마이저 힌트를 주입한다.
     * - MySQL 5.7.8+ 는 실제 timeout 효과 (단위 ms, SELECT 전용)
     * - MariaDB 는 옵티마이저 힌트를 일반 주석으로 보고 무시 → 호환성 안전
     * - 힌트는 SELECT 키워드 직후 첫 위치에 와야 동작. 이미 힌트가 있으면 덮지 않음.
     * - timeout_ms <= 0 이면 원본 SQL 그대로 반환 (kill switch).
     *
     * 순수 함수 — 단위 테스트 용이. 호출자가 config 상수 조회 후 인자로 전달.
     */
    public static function injectQueryTimeoutHint(string $sql, int $timeout_ms): string
    {
        if ($timeout_ms <= 0) return $sql;

        // 이미 옵티마이저 힌트가 있으면 그대로 (이중 주입 방지)
        if (preg_match('/\bSELECT\s+\/\*\+/i', $sql)) return $sql;

        // 첫 번째 SELECT 키워드 뒤에만 1회 삽입.
        // 서브쿼리에는 미적용 — 옵티마이저 힌트는 outer query 1개로 충분.
        return preg_replace(
            '/\bSELECT\b/i',
            sprintf('SELECT /*+ MAX_EXECUTION_TIME(%d) */', $timeout_ms),
            $sql,
            1
        );
    }
}
