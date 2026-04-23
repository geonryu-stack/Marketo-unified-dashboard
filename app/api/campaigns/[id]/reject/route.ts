/**
 * POST /api/campaigns/[id]/reject  — Phase 2 거절 (재검토 요청)
 *
 * 조건: status === 'awaiting_approval'
 * status를 'draft'로 되돌려 캠페인을 재편집 가능 상태로 만듭니다.
 */
import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { v4 as uuid } from 'uuid';

type Ctx = { params: Promise<{ id: string }> };

export async function POST(_req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const appDb = getDb();

  // 404 체크용으로만 캠페인 존재 여부 확인
  const exists = appDb.prepare('SELECT id FROM campaigns WHERE id = ?').get(id);
  if (!exists) return Response.json({ success: false, error: '캠페인을 찾을 수 없습니다.' }, { status: 404 });

  // ── 상태 게이트: 원자적 CAS ─────────────────────────────────────
  // 두 번 클릭하거나 동시에 approve가 진행 중인 경우를 차단합니다.
  const now = new Date().toISOString();
  const acquired = (appDb.prepare(
    `UPDATE campaigns SET status='draft', error_message=?, updated_at=?
     WHERE id=? AND status='awaiting_approval'`
  ).run('담당자 재검토 요청으로 초기화됨', now, id) as { changes: number });

  if (acquired.changes === 0) {
    const cur = appDb.prepare('SELECT status FROM campaigns WHERE id=?').get(id) as { status: string } | undefined;
    return Response.json(
      { success: false, error: `재검토 요청 불가: 현재 상태(${cur?.status ?? '?'}). 이미 처리 중입니다.` },
      { status: 409 }
    );
  }

  appDb.prepare(`
    INSERT INTO job_logs (id, campaign_id, step, status, message, created_at)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(uuid(), id, 'reject', 'done', '담당자가 재검토를 요청했습니다. 상태를 draft로 초기화했습니다.', now);

  return Response.json({ success: true, data: { status: 'draft' } });
}
