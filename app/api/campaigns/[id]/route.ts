import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { Campaign } from '@/lib/types';

type Ctx = { params: Promise<{ id: string }> };

export async function GET(_req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const db = getDb();
  const campaign = db.prepare('SELECT * FROM campaigns WHERE id = ?').get(id) as Campaign | undefined;
  if (!campaign) return Response.json({ success: false, error: '캠페인을 찾을 수 없습니다.' }, { status: 404 });
  return Response.json({ success: true, data: campaign });
}

export async function PUT(req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const body = await req.json();
  const db = getDb();

  const existing = db.prepare('SELECT id FROM campaigns WHERE id = ?').get(id);
  if (!existing) return Response.json({ success: false, error: '캠페인을 찾을 수 없습니다.' }, { status: 404 });

  const now = new Date().toISOString();
  // 'status'는 의도적으로 제외 — 모든 상태 전환은 전용 엔드포인트를 사용해야 합니다:
  // /confirm, /run, /approve, /reject, /cancel
  const allowed = [
    'name', 'reward_url', 'scheduled_at', 'send_time',
    'marketo_list_id', 'marketo_list_name',
    'marketo_cloned_email_id', 'marketo_campaign_id', 'lead_count', 'error_message',
  ];
  const sets = allowed.filter((k) => k in body).map((k) => `${k}=?`).join(', ');
  const vals = allowed.filter((k) => k in body).map((k) => body[k]);

  if (sets.length > 0) {
    db.prepare(`UPDATE campaigns SET ${sets}, updated_at=? WHERE id=?`).run(...vals, now, id);
  }

  const updated = db.prepare('SELECT * FROM campaigns WHERE id = ?').get(id);
  return Response.json({ success: true, data: updated });
}

export async function DELETE(_req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const db = getDb();
  db.prepare('DELETE FROM campaigns WHERE id = ?').run(id);
  db.prepare('DELETE FROM job_logs WHERE campaign_id = ?').run(id);
  return Response.json({ success: true });
}
