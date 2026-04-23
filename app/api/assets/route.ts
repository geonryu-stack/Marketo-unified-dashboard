import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { v4 as uuid } from 'uuid';
import { AssetLibraryItem } from '@/lib/types';

export async function GET() {
  const db = getDb();
  const rows = db.prepare('SELECT * FROM asset_library ORDER BY updated_at DESC').all() as AssetLibraryItem[];
  return Response.json({ success: true, data: rows });
}

export async function POST(req: NextRequest) {
  const body = await req.json();
  const {
    name, image_url = '', subject = '', emoji = '', preheader = '',
    body_text = '', tags = '', marketo_email_id = null, marketo_program_id = null,
    marketo_folder_id = null, reward_url_placeholder = '{{REWARD_URL}}',
    send_mode = 'clone',
    marketo_token_image = '', marketo_token_subject = '', marketo_token_preheader = '',
    marketo_token_body = '', marketo_token_emoji = '', marketo_token_reward_url = '',
  } = body;

  if (!name) return Response.json({ success: false, error: 'name 필수' }, { status: 400 });

  const now = new Date().toISOString();
  const id = uuid();
  const db = getDb();

  db.prepare(`
    INSERT INTO asset_library (
      id, name, image_url, subject, emoji, preheader, body_text, tags,
      marketo_email_id, marketo_program_id, marketo_folder_id, reward_url_placeholder,
      send_mode, marketo_token_image, marketo_token_subject, marketo_token_preheader,
      marketo_token_body, marketo_token_emoji, marketo_token_reward_url,
      created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    id, name, image_url, subject, emoji, preheader, body_text, tags,
    marketo_email_id, marketo_program_id, marketo_folder_id, reward_url_placeholder,
    send_mode, marketo_token_image, marketo_token_subject, marketo_token_preheader,
    marketo_token_body, marketo_token_emoji, marketo_token_reward_url,
    now, now
  );

  const item = db.prepare('SELECT * FROM asset_library WHERE id = ?').get(id);
  return Response.json({ success: true, data: item }, { status: 201 });
}
