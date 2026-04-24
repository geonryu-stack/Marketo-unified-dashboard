<?php
// src/InternalDB.php
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
            self::$instance = new PDO($dsn, INTERNAL_DB_USER, INTERNAL_DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$instance;
    }

    /** SELECT 전용 실행 — CONSTRAINT-01 */
    public static function query(string $sql, array $params = []): array
    {
        assert_readonly($sql);  // helpers.php의 assert_readonly 사용
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
