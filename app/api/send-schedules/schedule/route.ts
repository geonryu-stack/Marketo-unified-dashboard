import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { scheduleCampaign } from '@/lib/marketo';
import { DaySend, SendGroup } from '@/lib/types';

export const dynamic = 'force-dynamic';

/**
 * POST /api/send-schedules/schedule
 * Body: { groupId: string, weekStart: string }
 *
 * 해당 그룹·주의 test_sent 스케줄을 Marketo Smart Campaign으로 일괄 예약.
 * runAt = "YYYY-MM-DDThh:mm:ss" (계정 기본 타임존 적용 — KST 계정 기준)
 *
 * 참고: Batch Smart Campaign은 true RTZ를 지원하지 않습니다.
 * RTZ 플래그는 저장되나 실제 발송 시각 계산에는 반영되지 않습니다.
 * 진정한 RTZ는 Marketo Email Program 방식으로 전환 시 지원 가능합니다.
 */
export async function POST(req: NextRequest) {
  const body = await req.json() as { groupId: string; weekStart: string };
  const { groupId, weekStart } = body;

  if (!groupId || !weekStart) {
    return Response.json({ success: false, error: 'groupId, weekStart 필수' }, { status: 400 });
  }

  const db = getDb();

  const group = db
    .prepare('SELECT * FROM groups WHERE id = ?')
    .get(groupId) as SendGroup | undefined;
  if (!group) {
    return Response.json({ success: false, error: `그룹을 찾을 수 없습니다: ${groupId}` }, { status: 404 });
  }

  const weekEnd = new Date(weekStart + 'T00:00:00');
  weekEnd.setDate(weekEnd.getDate() + 6);
  const weekEndStr = weekEnd.toISOString().slice(0, 10);

  const targets = db
    .prepare(
      `SELECT * FROM send_schedules
       WHERE group_id = ? AND send_date BETWEEN ? AND ?
         AND status = 'test_sent'
       ORDER BY send_date ASC`
    )
    .all(groupId, weekStart, weekEndStr) as DaySend[];

  if (targets.length === 0) {
    return Response.json(
      { success: false, error: '테스트 완료된 스케줄이 없습니다. 먼저 테스트 발송을 진행하세요.' },
      { status: 400 }
    );
  }

  const results: { date: string; success: boolean; error?: string }[] = [];

  for (const schedule of targets) {
    try {
      // runAt = "YYYY-MM-DDThh:mm:ss" — Marketo 계정 타임존(KST) 기준
      const runAt = `${schedule.send_date}T${schedule.send_time}:00`;
      await scheduleCampaign(group.marketo_campaign_id, runAt);

      db.prepare(
        `UPDATE send_schedules SET status='scheduled', scheduled_at=?, updated_at=? WHERE id=?`
      ).run(new Date().toISOString(), new Date().toISOString(), schedule.id);
      results.push({ date: schedule.send_date, success: true });
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err);
      db.prepare(
        `UPDATE send_schedules SET status='failed', error_message=?, updated_at=? WHERE id=?`
      ).run(msg, new Date().toISOString(), schedule.id);
      results.push({ date: schedule.send_date, success: false, error: msg });
    }
  }

  const allOk = results.every((r) => r.success);
  return Response.json({ success: allOk, data: results }, { status: allOk ? 200 : 207 });
}
