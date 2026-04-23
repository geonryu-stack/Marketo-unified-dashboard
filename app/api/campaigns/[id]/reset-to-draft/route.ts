/**
 * POST /api/campaigns/[id]/reset-to-draft  — 수동 처리 후 초기화
 *
 * 취소 실패로 'cancelling' 상태에 갇힌 캠페인을 'draft'로 초기화합니다.
 *
 * 사용 시나리오:
 *   - Smart Campaign 예약 캠페인: Marketo에서 직접 SC를 비활성화한 뒤 호출
 *   - Email Program unapprove 실패 캠페인: Marketo에서 직접 unapprove한 뒤 호출
 *
 * 조건: status === 'cancelling'
 *
 * marketo_campaign_id도 초기화합니다. 다음 Phase 1 실행 시 승인 경로가 다시
 * Email Program 또는 Smart Campaign 방식을 자동 선택합니다.
 */
import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { v4 as uuid } from 'uuid';

type Ctx = { params: Promise<{ id: string }> };

export async function POST(_req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const appDb = getDb();

  const acquired = (appDb.prepare(
    `UPDATE campaigns
        SET status='draft', error_message=NULL, marketo_campaign_id=NULL, marketo_email_program_id=NULL, updated_at=?
      WHERE id=? AND status='cancelling'`
  ).run(new Date().toISOString(), id) as { changes: number });

  if (acquired.changes === 0) {
    const cur = appDb.prepare('SELECT status FROM campaigns WHERE id=?').get(id) as { status: string } | undefined;
    if (!cur) return Response.json({ success: false, error: '캠페인을 찾을 수 없습니다.' }, { status: 404 });
    return Response.json(
      { success: false, error: `초기화 불가: 현재 상태(${cur.status}). 'cancelling' 상태여야 합니다.` },
      { status: 409 }
    );
  }

  appDb.prepare(`
    INSERT INTO job_logs (id, campaign_id, step, status, message, created_at)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(
    uuid(), id, 'reset_to_draft', 'done',
    '담당자가 Marketo에서 직접 발송을 취소한 뒤 수동으로 초기화했습니다.',
    new Date().toISOString()
  );

  return Response.json({ success: true, data: { status: 'draft' } });
}
