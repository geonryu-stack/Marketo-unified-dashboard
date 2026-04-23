import { getDb } from '@/db/sqlite';
import { SendGroup } from '@/lib/types';

export const dynamic = 'force-dynamic';

export async function GET() {
  const db = getDb();
  const groups = db
    .prepare('SELECT * FROM groups ORDER BY sort_order ASC')
    .all() as SendGroup[];
  return Response.json({ success: true, data: groups });
}
