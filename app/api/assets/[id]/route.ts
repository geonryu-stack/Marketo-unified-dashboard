import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';

type Ctx = { params: Promise<{ id: string }> };

export async function GET(_req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const db = getDb();
  const item = db.prepare('SELECT * FROM asset_library WHERE id = ?').get(id);
  if (!item) return Response.json({ success: false, error: '에셋을 찾을 수 없습니다.' }, { status: 404 });
  return Response.json({ success: true, data: item });
}

export async function PUT(req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const body = await req.json();
  const db = getDb();

  const existing = db.prepare('SELECT id FROM asset_library WHERE id = ?').get(id);
  if (!existing) return Response.json({ success: false, error: '에셋을 찾을 수 없습니다.' }, { status: 404 });

  const {
    name, image_url, subject, emoji, preheader, body_text, tags,
    marketo_email_id, marketo_program_id, marketo_folder_id, reward_url_placeholder,
    send_mode, marketo_token_image, marketo_token_subject, marketo_token_preheader,
    marketo_token_body, marketo_token_emoji, marketo_token_reward_url,
  } = body;

  const now = new Date().toISOString();
  db.prepare(`
    UPDATE asset_library SET
      name=?, image_url=?, subject=?, emoji=?, preheader=?, body_text=?, tags=?,
      marketo_email_id=?, marketo_program_id=?, marketo_folder_id=?,
      reward_url_placeholder=?,
      send_mode=?, marketo_token_image=?, marketo_token_subject=?,
      marketo_token_preheader=?, marketo_token_body=?, marketo_token_emoji=?,
      marketo_token_reward_url=?, updated_at=?
    WHERE id=?
  `).run(
    name, image_url, subject, emoji, preheader, body_text, tags,
    marketo_email_id, marketo_program_id, marketo_folder_id,
    reward_url_placeholder ?? '{{REWARD_URL}}',
    send_mode ?? 'clone',
    marketo_token_image ?? '', marketo_token_subject ?? '',
    marketo_token_preheader ?? '', marketo_token_body ?? '', marketo_token_emoji ?? '',
    marketo_token_reward_url ?? '',
    now, id
  );

  const updated = db.prepare('SELECT * FROM asset_library WHERE id = ?').get(id);
  return Response.json({ success: true, data: updated });
}

export async function DELETE(_req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const db = getDb();
  db.prepare('DELETE FROM asset_library WHERE id = ?').run(id);
  return Response.json({ success: true });
}
