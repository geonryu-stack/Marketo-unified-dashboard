/**
 * POST /api/campaigns/[id]/confirm  — Cron 자동 실행 예약
 *
 * 조건: status === 'draft'
 * draft → confirmed 전환을 원자적 CAS로 처리합니다.
 * confirmed 상태가 되면 Cron이 scheduled_at 시각에 Phase 1을 자동 실행합니다.
 */
import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';

type Ctx = { params: Promise<{ id: string }> };

export async function POST(_req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const appDb = getDb();

  const exists = appDb.prepare('SELECT id FROM campaigns WHERE id = ?').get(id);
  if (!exists) return Response.json({ success: false, error: '캠페인을 찾을 수 없습니다.' }, { status: 404 });

  // ── 원자적 CAS: draft → confirmed ────────────────────────────
  // draft 상태에서만 허용. 진행 중·승인 대기·예약 완료 상태에서의
  // 실수 클릭(stale UI)을 서버 레벨에서 차단합니다.
  const acquired = (appDb.prepare(
    `UPDATE campaigns SET status='confirmed', error_message=NULL, updated_at=?
     WHERE id=? AND status='draft'`
  ).run(new Date().toISOString(), id) as { changes: number });

  if (acquired.changes === 0) {
    const cur = appDb.prepare('SELECT status FROM campaigns WHERE id=?').get(id) as { status: string } | undefined;
    return Response.json(
      { success: false, error: `자동 실행 예약 불가: 현재 상태(${cur?.status ?? '?'}). 'draft' 상태여야 합니다.` },
      { status: 409 }
    );
  }

  const updated = appDb.prepare('SELECT * FROM campaigns WHERE id=?').get(id);
  return Response.json({ success: true, data: updated });
}
