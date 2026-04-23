/**
 * POST /api/campaigns/[id]/approve  — Phase 2 (담당자 승인)
 *
 * 조건: status === 'awaiting_approval'
 *
 * seg.marketo_email_program_id는 Email Program ID입니다.
 *
 * Step A  Email Program Unapprove
 * Step B  My Token 주입 (setProgramMyTokens)
 * Step C  RTZ 스케줄 설정 (scheduleEmailProgram)
 * Step D  Email Program Approve
 *         → status = scheduled, marketo_email_program_id = EP ID 기록
 */
import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import {
  unapproveEmailProgram,
  setProgramMyTokens,
  scheduleEmailProgram,
  approveEmailProgram,
  buildEpTokenPayload,
} from '@/lib/marketo';
import { v4 as uuid } from 'uuid';
import { Campaign, Segment, AssetLibraryItem } from '@/lib/types';

type Ctx = { params: Promise<{ id: string }> };

function log(db: ReturnType<typeof getDb>, campaignId: string, step: string, status: string, message?: string) {
  db.prepare(`
    INSERT INTO job_logs (id, campaign_id, step, status, message, created_at)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(uuid(), campaignId, step, status, message ?? null, new Date().toISOString());
}

function setStatus(db: ReturnType<typeof getDb>, campaignId: string, status: string, errorMessage?: string) {
  db.prepare(`UPDATE campaigns SET status=?, error_message=?, updated_at=? WHERE id=?`)
    .run(status, errorMessage ?? null, new Date().toISOString(), campaignId);
}

export async function POST(_req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const appDb = getDb();

  const campaign = appDb.prepare('SELECT * FROM campaigns WHERE id = ?').get(id) as Campaign | undefined;
  if (!campaign) return Response.json({ success: false, error: '캠페인을 찾을 수 없습니다.' }, { status: 404 });

  const seg = appDb.prepare('SELECT * FROM segments WHERE id = ?').get(campaign.segment_id) as Segment | undefined;
  if (!seg) return Response.json({ success: false, error: '세그먼트를 찾을 수 없습니다.' }, { status: 404 });

  const asset = appDb.prepare('SELECT * FROM asset_library WHERE id = ?').get(campaign.asset_library_id) as AssetLibraryItem | undefined;

  if (!seg.marketo_email_program_id) {
    return Response.json(
      { success: false, error: '세그먼트에 Email Program ID가 설정되지 않았습니다. 세그먼트 설정에서 "Email Program ID"를 입력하세요.' },
      { status: 400 }
    );
  }
  const epId = parseInt(seg.marketo_email_program_id, 10);
  if (isNaN(epId)) {
    return Response.json(
      { success: false, error: `세그먼트의 Email Program ID(${seg.marketo_email_program_id})가 유효한 숫자가 아닙니다.` },
      { status: 400 }
    );
  }

  // ── 상태 게이트: 원자적 CAS ─────────────────────────────────────
  // awaiting_approval → scheduling 전환을 단일 UPDATE로 처리.
  // 동시에 두 번 승인 클릭하거나 Phase 1이 재실행되는 경우를 차단합니다.
  const acquired = (appDb.prepare(
    `UPDATE campaigns SET status='scheduling', error_message=NULL, updated_at=?
     WHERE id=? AND status='awaiting_approval'`
  ).run(new Date().toISOString(), id) as { changes: number });

  if (acquired.changes === 0) {
    const cur = appDb.prepare('SELECT status FROM campaigns WHERE id=?').get(id) as { status: string } | undefined;
    return Response.json(
      { success: false, error: `승인 불가: 현재 상태(${cur?.status ?? '?'}). 이미 처리 중이거나 상태가 변경되었습니다. 페이지를 새로고침하세요.` },
      { status: 409 }
    );
  }

  const startDate = campaign.scheduled_at.slice(0, 10);  // "YYYY-MM-DD"
  const rawTime = campaign.send_time?.trim() || '10:00';
  // HH:MM → HH:MM:SS 정규화
  const startTime = rawTime.includes(':') && rawTime.split(':').length === 2
    ? `${rawTime}:00`
    : rawTime;
  // RTZ 모드: HH:MM:SS에서 초(:SS) 제거 — Marketo EP schedule API가 HH:MM 포맷 선호
  const scheduleTime = startTime.slice(0, 5); // "HH:MM"

  try {
    // ── Step A: Email Program Unapprove ──────────────────────────────────
    log(appDb, id, 'ep_unapprove', 'running', `Email Program(${epId}) unapprove 시작`);
    await unapproveEmailProgram(epId);
    log(appDb, id, 'ep_unapprove', 'done', 'unapprove 완료');

    // ── Step B: My Token 주입 ────────────────────────────────────────────
    const epTokens = asset ? buildEpTokenPayload(asset, campaign) : [];
    if (epTokens.length > 0) {
      log(appDb, id, 'set_ep_tokens', 'running', `My Token ${epTokens.length}개 주입 중`);
      await setProgramMyTokens(epId, epTokens);
      log(appDb, id, 'set_ep_tokens', 'done', `My Token ${epTokens.length}개 설정 완료`);
    }

    // ── Step C: RTZ 스케줄 설정 ──────────────────────────────────────────
    log(appDb, id, 'ep_schedule', 'running',
      `Email Program(${epId}) RTZ 스케줄 설정: ${startDate} ${scheduleTime}`);
    await scheduleEmailProgram(epId, startDate, scheduleTime, true);
    log(appDb, id, 'ep_schedule', 'done', `스케줄 설정 완료 (RTZ 활성화)`);

    // ── Step D: Email Program Approve ────────────────────────────────────
    log(appDb, id, 'ep_approve', 'running', `Email Program(${epId}) approve 시작`);
    await approveEmailProgram(epId);
    log(appDb, id, 'ep_approve', 'done', 'approve 완료');

    // EP ID를 marketo_email_program_id에 기록 — cancel/route.ts가 unapprove에 사용
    appDb.prepare(
      `UPDATE campaigns SET marketo_email_program_id=?, marketo_campaign_id=NULL, status='scheduled', updated_at=? WHERE id=?`
    ).run(String(epId), new Date().toISOString(), id);

    return Response.json({
      success: true,
      data: { status: 'scheduled', startDate, startTime: scheduleTime, method: 'email_program_rtz' },
    });

  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    const failMsg = `Marketo Email Program 예약 실패: ${msg}. Marketo UI에서 Email Program(ID: ${epId})을 직접 승인/예약해주세요.`;
    log(appDb, id, 'ep_flow', 'error', failMsg);
    setStatus(appDb, id, 'failed', failMsg);
    return Response.json({ success: false, error: failMsg }, { status: 500 });
  }
}
