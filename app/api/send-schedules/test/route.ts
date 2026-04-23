import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { sendSampleEmail } from '@/lib/marketo';
import { DaySend } from '@/lib/types';

export const dynamic = 'force-dynamic';

const TEST_EMAILS = (process.env.SEND_TEST_EMAIL_TO || '')
  .split(',').map((e) => e.trim()).filter(Boolean);

/**
 * POST /api/send-schedules/test
 * Body: { groupId: string, weekStart: string }
 *
 * 해당 그룹·주의 draft 상태 스케줄에 순서대로 테스트 메일 발송.
 * 각 날짜별 sendSampleEmail 호출 후 status → 'test_sent'.
 */
export async function POST(req: NextRequest) {
  const body = await req.json() as { groupId: string; weekStart: string };
  const { groupId, weekStart } = body;

  if (!groupId || !weekStart) {
    return Response.json({ success: false, error: 'groupId, weekStart 필수' }, { status: 400 });
  }
  if (TEST_EMAILS.length === 0) {
    return Response.json(
      { success: false, error: 'SEND_TEST_EMAIL_TO 환경변수가 설정되지 않았습니다.' },
      { status: 500 }
    );
  }

  const weekEnd = new Date(weekStart + 'T00:00:00');
  weekEnd.setDate(weekEnd.getDate() + 6);
  const weekEndStr = weekEnd.toISOString().slice(0, 10);

  const db = getDb();
  const targets = db
    .prepare(
      `SELECT * FROM send_schedules
       WHERE group_id = ? AND send_date BETWEEN ? AND ?
         AND status IN ('draft', 'test_sent')
       ORDER BY send_date ASC`
    )
    .all(groupId, weekStart, weekEndStr) as DaySend[];

  if (targets.length === 0) {
    return Response.json({ success: false, error: '테스트할 활성 스케줄이 없습니다.' }, { status: 400 });
  }

  const results: { date: string; success: boolean; error?: string }[] = [];

  for (const schedule of targets) {
    try {
      if (!schedule.marketo_email_id) {
        results.push({ date: schedule.send_date, success: false, error: '에셋을 먼저 선택해주세요.' });
        continue;
      }
      for (const addr of TEST_EMAILS) {
        await sendSampleEmail(schedule.marketo_email_id, addr);
      }
      db.prepare(
        `UPDATE send_schedules SET status='test_sent', test_sent_at=?, updated_at=? WHERE id=?`
      ).run(new Date().toISOString(), new Date().toISOString(), schedule.id);
      results.push({ date: schedule.send_date, success: true });
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err);
      db.prepare(
        `UPDATE send_schedules SET error_message=?, updated_at=? WHERE id=?`
      ).run(msg, new Date().toISOString(), schedule.id);
      results.push({ date: schedule.send_date, success: false, error: msg });
    }
  }

  const allOk = results.every((r) => r.success);
  return Response.json({ success: allOk, data: results }, { status: allOk ? 200 : 207 });
}
