/**
 * POST /api/segments/[id]/extract
 * 사내 DB에서 세그먼트 대상자를 추출합니다.
 * CONSTRAINT-01: SELECT 쿼리만 허용
 */
import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { getInternalDb, assertReadOnly } from '@/db/internal';
import { buildWhereClause } from '@/lib/utils';
import { FIELD_DEFS } from '@/lib/field-defs';
import { Segment, FilterCondition } from '@/lib/types';

type Ctx = { params: Promise<{ id: string }> };

const EMAIL_FIELD = process.env.INTERNAL_DB_EMAIL_FIELD || 'email';
const TABLE = process.env.INTERNAL_DB_TABLE || 'users';

export async function POST(_req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const appDb = getDb();

  const seg = appDb.prepare('SELECT * FROM segments WHERE id = ?').get(id) as Segment | undefined;
  if (!seg) return Response.json({ success: false, error: '세그먼트를 찾을 수 없습니다.' }, { status: 404 });

  const filters: FilterCondition[] =
    typeof seg.filters === 'string' ? JSON.parse(seg.filters) : seg.filters;

  try {
    const { sql: whereClause, params: queryParams } = buildWhereClause(filters, FIELD_DEFS);
    const countSql = `SELECT COUNT(*) AS cnt FROM \`${TABLE}\` WHERE ${whereClause}`;
    const emailSql = `SELECT \`${EMAIL_FIELD}\` AS email FROM \`${TABLE}\` WHERE ${whereClause}`;

    assertReadOnly(countSql);
    assertReadOnly(emailSql);

    const internalDb = getInternalDb();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const [countRows] = await internalDb.execute(countSql, queryParams as any[]);
    const count = (countRows as { cnt: number }[])[0]?.cnt ?? 0;

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const [emailRows] = await internalDb.execute(emailSql, queryParams as any[]);
    const emails = (emailRows as { email: string }[]).map((r) => r.email).filter(Boolean);

    const now = new Date().toISOString();
    appDb.prepare(`
      UPDATE segments SET last_count=?, last_extracted_at=?, updated_at=? WHERE id=?
    `).run(count, now, now, id);

    return Response.json({ success: true, data: { count, emails } });
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    return Response.json({ success: false, error: msg }, { status: 500 });
  }
}
