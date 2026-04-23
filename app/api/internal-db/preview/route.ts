/**
 * POST /api/internal-db/preview
 * 세그먼트 필터로 대상자 수를 미리보기합니다.
 */
import { NextRequest } from 'next/server';
import { getInternalDb, assertReadOnly } from '@/db/internal';
import { buildWhereClause } from '@/lib/utils';
import { FIELD_DEFS } from '@/lib/field-defs';
import { FilterCondition } from '@/lib/types';

const TABLE = process.env.INTERNAL_DB_TABLE || 'users';

export async function POST(req: NextRequest) {
  try {
    const body = await req.json();
    const filters: FilterCondition[] = body.filters ?? [];

    const { sql: whereClause, params } = buildWhereClause(filters, FIELD_DEFS);
    const countSql = `SELECT COUNT(*) AS cnt FROM \`${TABLE}\` WHERE ${whereClause}`;
    assertReadOnly(countSql);

    const db = getInternalDb();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const [rows] = await db.execute(countSql, params as any[]);
    const count = (rows as { cnt: number }[])[0]?.cnt ?? 0;

    return Response.json({ success: true, data: { count } });
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    return Response.json({ success: false, error: msg }, { status: 500 });
  }
}
