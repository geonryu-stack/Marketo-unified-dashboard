/**
 * POST /api/campaigns/[id]/cancel  — 발송 예약 취소
 *
 * 조건: status === 'scheduled' 또는 'cancelling'
 *
 * 2단계 처리:
 *   1. CAS: scheduled → cancelling  (Phase 1 실행 불가 상태로 먼저 진입)
 *   2. Email Program unapprove API 호출
 *      성공 → status = 'draft' 복귀 (재실행 가능)
 *      실패 → 'cancelling' 유지 + 수동 취소 안내 (reset-to-draft 버튼으로 복구)
 *
 * campaigns.marketo_email_program_id에 EP ID가 기록되어 있으면 API 취소.
 * 없으면 segments.marketo_email_program_id fallback.
 * 그래도 없으면 수동 취소 안내.
 */
import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { v4 as uuid } from 'uuid';
import { Campaign, Segment } from '@/lib/types';
import { unapproveEmailProgram } from '@/lib/marketo';

type Ctx = { params: Promise<{ id: string }> };

export async function POST(_req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const appDb = getDb();

  const exists = appDb.prepare('SELECT id FROM campaigns WHERE id = ?').get(id);
  if (!exists) return Response.json({ success: false, error: '캠페인을 찾을 수 없습니다.' }, { status: 404 });

  // ── Step 1: CAS scheduled → cancelling ───────────────────────
  // 'cancelling'은 /run 허용 목록에 없으므로 Phase 1 진입이 차단됩니다.
  const now = new Date().toISOString();
  // 'cancelling'도 허용: 서버 크래시 등으로 중간 상태에서 멈춘 경우 재시도를 허용합니다.
  const acquired = (appDb.prepare(
    `UPDATE campaigns SET status='cancelling', error_message=NULL, updated_at=?
     WHERE id=? AND status IN ('scheduled','cancelling')`
  ).run(now, id) as { changes: number });

  if (acquired.changes === 0) {
    const cur = appDb.prepare('SELECT status FROM campaigns WHERE id=?').get(id) as { status: string } | undefined;
    return Response.json(
      { success: false, error: `취소 불가: 현재 상태(${cur?.status ?? '?'}). 'scheduled' 또는 'cancelling' 상태여야 합니다.` },
      { status: 409 }
    );
  }

  // ── Step 2: Email Program unapprove API로 실제 취소 ─────────────────
  const logNow = new Date().toISOString();
  const campaign = appDb
    .prepare('SELECT marketo_email_program_id, segment_id FROM campaigns WHERE id=?')
    .get(id) as Pick<Campaign, 'marketo_email_program_id' | 'segment_id'>;

  // campaigns.marketo_email_program_id 우선, 없으면 segments에서 fallback
  let epIdStr = campaign.marketo_email_program_id ?? '';
  if (!epIdStr) {
    const seg = appDb
      .prepare('SELECT marketo_email_program_id FROM segments WHERE id=?')
      .get(campaign.segment_id) as Pick<Segment, 'marketo_email_program_id'> | undefined;
    epIdStr = seg?.marketo_email_program_id ?? '';
  }

  const epId = parseInt(epIdStr, 10);

  if (!epIdStr || isNaN(epId)) {
    const manualMsg =
      `예약 취소: Email Program ID를 확인할 수 없습니다. ` +
      `Marketo에서 직접 Email Program을 unapprove한 뒤 "수동 취소 완료 → 초기화" 버튼을 클릭하세요.`;
    appDb.prepare(`UPDATE campaigns SET error_message=?, updated_at=? WHERE id=?`)
      .run(manualMsg, now, id);
    appDb.prepare(`INSERT INTO job_logs (id, campaign_id, step, status, message, created_at) VALUES (?,?,?,?,?,?)`)
      .run(uuid(), id, 'cancel', 'error', manualMsg, logNow);
    return Response.json({ success: true, data: { status: 'cancelling', message: manualMsg } });
  }

  try {
    await unapproveEmailProgram(epId);

    appDb.prepare(
      `UPDATE campaigns SET status='draft', marketo_email_program_id=NULL, error_message=NULL, updated_at=? WHERE id=?`
    ).run(now, id);
    appDb.prepare(`INSERT INTO job_logs (id, campaign_id, step, status, message, created_at) VALUES (?,?,?,?,?,?)`)
      .run(uuid(), id, 'cancel', 'done', `Email Program(${epId}) unapprove 완료 → draft 복귀`, logNow);

    return Response.json({ success: true, data: { status: 'draft' } });

  } catch (err) {
    const errMsg = err instanceof Error ? err.message : String(err);
    const failMsg =
      `Email Program(${epId}) unapprove 실패: ${errMsg}. ` +
      `Marketo에서 직접 Email Program을 unapprove한 뒤 "수동 취소 완료 → 초기화" 버튼을 클릭하세요.`;

    appDb.prepare(`UPDATE campaigns SET error_message=?, updated_at=? WHERE id=?`)
      .run(failMsg, now, id);
    appDb.prepare(`INSERT INTO job_logs (id, campaign_id, step, status, message, created_at) VALUES (?,?,?,?,?,?)`)
      .run(uuid(), id, 'cancel', 'error', failMsg, logNow);

    return Response.json({ success: true, data: { status: 'cancelling', message: failMsg } });
  }
}
