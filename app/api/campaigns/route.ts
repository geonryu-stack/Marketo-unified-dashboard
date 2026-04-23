import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { v4 as uuid } from 'uuid';
import { Campaign } from '@/lib/types';

export async function GET() {
  const db = getDb();
  const rows = db.prepare('SELECT * FROM campaigns ORDER BY created_at DESC').all() as Campaign[];
  return Response.json({ success: true, data: rows });
}

export async function POST(req: NextRequest) {
  const body = await req.json();
  const {
    name, segment_id, segment_name, asset_library_id, asset_name,
    reward_url, scheduled_at, send_time = '',
  } = body;

  if (!name || !segment_id || !asset_library_id || !scheduled_at) {
    return Response.json({ success: false, error: '필수 필드 누락' }, { status: 400 });
  }

  const now = new Date().toISOString();
  const id = uuid();
  const db = getDb();

  db.prepare(`
    INSERT INTO campaigns (
      id, name, segment_id, segment_name, asset_library_id, asset_name,
      reward_url, scheduled_at, send_time, status, lead_count, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 0, ?, ?)
  `).run(id, name, segment_id, segment_name, asset_library_id, asset_name, reward_url ?? '', scheduled_at, send_time, now, now);

  const campaign = db.prepare('SELECT * FROM campaigns WHERE id = ?').get(id);
  return Response.json({ success: true, data: campaign }, { status: 201 });
}
