import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';

type Ctx = { params: Promise<{ id: string }> };

export async function GET(_req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const db = getDb();
  const logs = db.prepare(
    'SELECT * FROM job_logs WHERE campaign_id = ? ORDER BY created_at ASC'
  ).all(id);
  return Response.json({ success: true, data: logs });
}
