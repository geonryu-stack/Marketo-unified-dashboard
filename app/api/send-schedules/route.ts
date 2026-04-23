import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { DaySend } from '@/lib/types';
import { v4 as uuid } from 'uuid';

export const dynamic = 'force-dynamic';

/** GET /api/send-schedules?groupId=active-a&weekStart=2025-04-28 */
export async function GET(req: NextRequest) {
  const { searchParams } = req.nextUrl;
  const groupId = searchParams.get('groupId');
  const weekStart = searchParams.get('weekStart'); // YYYY-MM-DD (월요일)

  if (!groupId || !weekStart) {
    return Response.json({ success: false, error: 'groupId, weekStart 파라미터 필요' }, { status: 400 });
  }

  // weekStart(월)부터 +6일(일)까지
  const weekEnd = new Date(weekStart + 'T00:00:00');
  weekEnd.setDate(weekEnd.getDate() + 6);
  const weekEndStr = weekEnd.toISOString().slice(0, 10);

  const db = getDb();
  const schedules = db
    .prepare(
      `SELECT * FROM send_schedules
       WHERE group_id = ? AND send_date BETWEEN ? AND ?
       ORDER BY send_date ASC`
    )
    .all(groupId, weekStart, weekEndStr) as DaySend[];

  return Response.json({ success: true, data: schedules });
}

/** PUT /api/send-schedules — upsert (토글 ON 시 저장) */
export async function PUT(req: NextRequest) {
  const body = await req.json() as {
    groupId: string;
    date: string;
    marketoEmailId: number;
    marketoEmailName: string;
    sendTime: string;
    timezone: 'RTZ' | 'KST';
  };

  const { groupId, date, marketoEmailId, marketoEmailName, sendTime, timezone } = body;
  if (!groupId || !date || !marketoEmailId || !sendTime) {
    return Response.json({ success: false, error: 'groupId, date, marketoEmailId, sendTime 필수' }, { status: 400 });
  }

  const db = getDb();
  const now = new Date().toISOString();

  // 이미 scheduled/sent 상태인 경우 수정 불가
  const existing = db
    .prepare('SELECT status FROM send_schedules WHERE group_id = ? AND send_date = ?')
    .get(groupId, date) as { status: string } | undefined;

  if (existing && (existing.status === 'scheduled' || existing.status === 'sent')) {
    return Response.json(
      { success: false, error: `이미 ${existing.status} 상태입니다. 수정하려면 예약을 취소하세요.` },
      { status: 409 }
    );
  }

  db.prepare(`
    INSERT INTO send_schedules
      (id, group_id, send_date, marketo_email_id, marketo_email_name, send_time, timezone, status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)
    ON CONFLICT(group_id, send_date) DO UPDATE SET
      marketo_email_id   = excluded.marketo_email_id,
      marketo_email_name = excluded.marketo_email_name,
      send_time          = excluded.send_time,
      timezone           = excluded.timezone,
      status             = CASE WHEN status IN ('scheduled','sent') THEN status ELSE 'draft' END,
      updated_at         = excluded.updated_at
  `).run(uuid(), groupId, date, marketoEmailId, marketoEmailName, sendTime, timezone, now, now);

  const saved = db
    .prepare('SELECT * FROM send_schedules WHERE group_id = ? AND send_date = ?')
    .get(groupId, date) as DaySend;

  return Response.json({ success: true, data: saved });
}

/** DELETE /api/send-schedules?groupId=active-a&date=2025-04-29 — 토글 OFF 시 삭제 */
export async function DELETE(req: NextRequest) {
  const { searchParams } = req.nextUrl;
  const groupId = searchParams.get('groupId');
  const date = searchParams.get('date');

  if (!groupId || !date) {
    return Response.json({ success: false, error: 'groupId, date 파라미터 필요' }, { status: 400 });
  }

  const db = getDb();
  const existing = db
    .prepare('SELECT status FROM send_schedules WHERE group_id = ? AND send_date = ?')
    .get(groupId, date) as { status: string } | undefined;

  if (existing?.status === 'scheduled' || existing?.status === 'sent') {
    return Response.json(
      { success: false, error: `이미 ${existing.status} 상태입니다. 예약을 먼저 취소하세요.` },
      { status: 409 }
    );
  }

  db.prepare('DELETE FROM send_schedules WHERE group_id = ? AND send_date = ?').run(groupId, date);
  return Response.json({ success: true });
}
