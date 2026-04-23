import mysql from 'mysql2/promise';

let _pool: mysql.Pool | null = null;

export function getInternalDb(): mysql.Pool {
  if (!_pool) {
    _pool = mysql.createPool({
      host: process.env.INTERNAL_DB_HOST || 'localhost',
      port: parseInt(process.env.INTERNAL_DB_PORT || '3306', 10),
      user: process.env.INTERNAL_DB_USER || '',
      password: process.env.INTERNAL_DB_PASSWORD || '',
      database: process.env.INTERNAL_DB_NAME || '',
      waitForConnections: true,
      connectionLimit: 3,
      // Read-only intent — enforced at DB account level per CONSTRAINT-01
    });
  }
  return _pool;
}

/** SELECT 쿼리만 허용 (CONSTRAINT-01) */
export function assertReadOnly(sql: string): void {
  const normalized = sql.trim().toUpperCase();
  if (!normalized.startsWith('SELECT') && !normalized.startsWith('WITH')) {
    throw new Error('CONSTRAINT-01: 사내 DB는 SELECT 쿼리만 허용됩니다.');
  }
}
