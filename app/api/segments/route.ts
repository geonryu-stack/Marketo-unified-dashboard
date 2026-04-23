import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { v4 as uuid } from 'uuid';
import { Segment } from '@/lib/types';

export async function GET() {
  const db = getDb();
  const rows = db.prepare('SELECT * FROM segments ORDER BY updated_at DESC').all() as Segment[];
  const segments = rows.map((r) => ({
    ...r,
    filters: typeof r.filters === 'string' ? JSON.parse(r.filters) : r.filters,
  }));
  return Response.json({ success: true, data: segments });
}

export async function POST(req: NextRequest) {
  const body = await req.json();
  const {
    name,
    description = '',
    filters = [],
    marketo_program_id = '',
    marketo_audience_list_id = '',
    marketo_email_program_id = '',
    is_recurring = 0,
    send_day_of_week = 1,
    recurring_send_time = '10:00',
  } = body;

  if (!name) return Response.json({ success: false, error: 'name 필수' }, { status: 400 });

  const now = new Date().toISOString();
  const id = uuid();
  const db = getDb();

  db.prepare(`
    INSERT INTO segments (id, name, description, filters, marketo_program_id, marketo_audience_list_id, marketo_email_program_id, is_recurring, send_day_of_week, recurring_send_time, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(id, name, description, JSON.stringify(filters), marketo_program_id, marketo_audience_list_id, marketo_email_program_id, is_recurring, send_day_of_week, recurring_send_time, now, now);

  const seg = db.prepare('SELECT * FROM segments WHERE id = ?').get(id) as Segment;
  return Response.json({
    success: true,
    data: { ...seg, filters: JSON.parse(seg.filters as unknown as string) },
  }, { status: 201 });
}
