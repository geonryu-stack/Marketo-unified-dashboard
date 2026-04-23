import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { Segment } from '@/lib/types';

type Ctx = { params: Promise<{ id: string }> };

export async function GET(_req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const db = getDb();
  const row = db.prepare('SELECT * FROM segments WHERE id = ?').get(id) as Segment | undefined;
  if (!row) return Response.json({ success: false, error: '세그먼트를 찾을 수 없습니다.' }, { status: 404 });
  return Response.json({ success: true, data: { ...row, filters: JSON.parse(row.filters as unknown as string) } });
}

export async function PUT(req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const body = await req.json();
  const {
    name,
    description,
    filters,
    marketo_program_id,
    marketo_audience_list_id,
    marketo_email_program_id,
    is_recurring,
    send_day_of_week,
    recurring_send_time,
  } = body;

  const db = getDb();
  const existing = db.prepare('SELECT id FROM segments WHERE id = ?').get(id);
  if (!existing) return Response.json({ success: false, error: '세그먼트를 찾을 수 없습니다.' }, { status: 404 });

  const now = new Date().toISOString();
  db.prepare(`
    UPDATE segments SET
      name=?, description=?, filters=?,
      marketo_program_id=?, marketo_audience_list_id=?, marketo_email_program_id=?,
      is_recurring=?, send_day_of_week=?, recurring_send_time=?,
      updated_at=?
    WHERE id=?
  `).run(
    name,
    description ?? '',
    JSON.stringify(filters ?? []),
    marketo_program_id ?? '',
    marketo_audience_list_id ?? '',
    marketo_email_program_id ?? '',
    is_recurring ?? 0,
    send_day_of_week ?? 1,
    recurring_send_time ?? '10:00',
    now,
    id
  );

  const updated = db.prepare('SELECT * FROM segments WHERE id = ?').get(id) as Segment;
  return Response.json({
    success: true,
    data: { ...updated, filters: JSON.parse(updated.filters as unknown as string) },
  });
}

export async function DELETE(_req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const db = getDb();
  db.prepare('DELETE FROM segments WHERE id = ?').run(id);
  return Response.json({ success: true });
}
