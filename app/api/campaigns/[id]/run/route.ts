/**
 * POST /api/campaigns/[id]/run  — Phase 1 (자동 실행)
 *
 * 파이프라인:
 *  Step 1   내부 DB 대상자 추출 (또는 INTERNAL_DB_BYPASS_LEADS 우회 모드)
 *  Step 2   Marketo 리드 업서트
 *  Step 3   세그먼트 고정 Static List 갱신 (기존 멤버 제거 → 새 리드 추가)
 *  Step 4   테스트 메일 발송 → status = awaiting_approval → 반환
 *
 * Program My Token API(/rest/asset/v1/program/{id}/tokens.json)는 EP ID가 올바르게
 * 설정된 경우 Step 3.5에서 사용합니다. 실패 시 non-fatal로 처리합니다.
 *
 * Phase 2 (담당자 승인) 는 /api/campaigns/[id]/approve 에서 처리합니다.
 * CONSTRAINT-07: 각 단계마다 job_logs에 기록
 */
import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { getInternalDb, assertReadOnly } from '@/db/internal';
import { buildWhereClause } from '@/lib/utils';
import { FIELD_DEFS } from '@/lib/field-defs';
import {
  upsertLeads, addLeadsToList,
  getListLeadIds, removeLeadsFromList,
  sendSampleEmail, setProgramMyTokens, buildEpTokenPayload,
} from '@/lib/marketo';
import { v4 as uuid } from 'uuid';
import { Campaign, Segment, AssetLibraryItem, FilterCondition } from '@/lib/types';
import { generateToken, sendApprovalEmail } from '@/lib/approval-mailer';

type Ctx = { params: Promise<{ id: string }> };

const EMAIL_FIELD = process.env.INTERNAL_DB_EMAIL_FIELD || 'email';
const TABLE = process.env.INTERNAL_DB_TABLE || 'users';
// 쉼표 구분 다중 수신자 지원 (예: "a@b.com,c@d.com")
const TEST_EMAILS = (process.env.SEND_TEST_EMAIL_TO || '')
  .split(',').map((e) => e.trim()).filter(Boolean);
// DB 우회 모드: 사내 DB 미연결 상태에서 Marketo 연동 테스트용
// INTERNAL_DB_BYPASS_LEADS=a@b.com,c@d.com 설정 시 DB 추출 단계를 건너뜁니다.
const BYPASS_LEADS = (process.env.INTERNAL_DB_BYPASS_LEADS || '')
  .split(',').map((e) => e.trim()).filter(Boolean);

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

  const asset = appDb.prepare('SELECT * FROM asset_library WHERE id = ?').get(campaign.asset_library_id) as AssetLibraryItem | undefined;
  if (!asset) return Response.json({ success: false, error: '에셋을 찾을 수 없습니다.' }, { status: 404 });

  const seg = appDb.prepare('SELECT * FROM segments WHERE id = ?').get(campaign.segment_id) as Segment | undefined;
  if (!seg) return Response.json({ success: false, error: '세그먼트를 찾을 수 없습니다.' }, { status: 404 });

  // 필수 사전 검증
  if (!campaign.reward_url) {
    return Response.json({ success: false, error: '보상 URL이 입력되지 않았습니다.' }, { status: 400 });
  }
  if (!seg.marketo_audience_list_id) {
    return Response.json({ success: false, error: '세그먼트에 Audience Static List ID가 설정되지 않았습니다.' }, { status: 400 });
  }
  if (!seg.marketo_email_program_id) {
    return Response.json(
      { success: false, error: '세그먼트에 Email Program ID가 설정되지 않았습니다. 세그먼트 설정에서 "Email Program ID"를 입력하세요.' },
      { status: 400 }
    );
  }
  if (!seg.marketo_program_id) {
    return Response.json({ success: false, error: '세그먼트에 Marketo Smart Campaign ID가 설정되지 않았습니다.' }, { status: 400 });
  }
  if (TEST_EMAILS.length === 0) {
    return Response.json({ success: false, error: 'SEND_TEST_EMAIL_TO 환경변수가 설정되지 않았습니다.' }, { status: 500 });
  }

  // ── 세그먼트 레벨 차단 + CAS: BEGIN IMMEDIATE 트랜잭션으로 원자적 처리 ──
  // BEGIN IMMEDIATE는 트랜잭션 시작 즉시 SQLite write lock(RESERVED lock)을
  // 획득한다. 이로써 SELECT(sibling 체크) 전에 lock이 잡히므로
  // TOCTOU(Time-of-Check-Time-of-Use) 경쟁 조건이 완전히 제거된다.
  // (BEGIN DEFERRED는 첫 번째 쓰기 시점에 lock을 획득하므로 SELECT 단계가 보호되지 않음)
  //
  // 반환 케이스:
  //  ok=true          → extracting으로 전환 성공, 진행
  //  reason='sibling' → 같은 세그먼트의 다른 캠페인이 점유 중 → 409
  //  reason='cas'     → 이 캠페인 자체가 이미 다른 상태로 전환됨 → 409
  type LockResult =
    | { ok: true }
    | { ok: false; reason: 'sibling'; sibling: { id: string; name: string; status: string } }
    | { ok: false; reason: 'cas'; currentStatus: string | undefined };

  const acquireLock = appDb.transaction((): LockResult => {
    const sibling = appDb.prepare(`
      SELECT id, name, status FROM campaigns
      WHERE segment_id = ? AND id != ?
        AND status IN (
          'extracting','uploading','preparing','awaiting_approval',
          'scheduling','scheduled','cancelling'
        )
      LIMIT 1
    `).get(campaign.segment_id, id) as { id: string; name: string; status: string } | undefined;

    if (sibling) return { ok: false, reason: 'sibling', sibling };

    const { changes } = appDb.prepare(
      `UPDATE campaigns SET status='extracting', error_message=NULL, updated_at=?
       WHERE id=? AND status IN ('draft','confirmed','failed')`
    ).run(new Date().toISOString(), id) as { changes: number };

    if (changes === 0) {
      const cur = appDb.prepare('SELECT status FROM campaigns WHERE id=?').get(id) as { status: string } | undefined;
      return { ok: false, reason: 'cas', currentStatus: cur?.status };
    }

    return { ok: true };
  });

  let lockResult: LockResult;
  try {
    lockResult = acquireLock.immediate();
  } catch (err: unknown) {
    // BEGIN IMMEDIATE가 write lock 획득에 실패하면 SQLITE_BUSY를 던진다.
    // 이는 다른 요청이 동시에 같은 트랜잭션을 실행 중임을 의미하므로 503으로 응답한다.
    const isBusy =
      typeof err === 'object' && err !== null &&
      'code' in err && (err as { code: unknown }).code === 'SQLITE_BUSY';
    if (isBusy) {
      return Response.json(
        { success: false, error: '다른 요청이 처리 중입니다. 잠시 후 다시 시도하세요.' },
        { status: 503 }
      );
    }
    throw err;
  }

  if (!lockResult.ok) {
    if (lockResult.reason === 'sibling') {
      return Response.json(
        {
          success: false,
          error: `세그먼트 동시 실행 차단: "${lockResult.sibling.name}" 캠페인이 이미 진행 중입니다. (상태: ${lockResult.sibling.status})`,
        },
        { status: 409 }
      );
    }
    // reason === 'cas': 이 캠페인 자체의 상태가 이미 바뀐 경우
    // 'scheduled'는 허용하지 않습니다: Marketo Email Program이 이미 approve되어
    // 발송 대기 중인 상태에서 Phase 1을 실행하면 Static List가 초기화되어
    // 수신자 목록이 비거나 오염될 수 있습니다.
    const isScheduled = lockResult.currentStatus === 'scheduled';
    const hint = isScheduled
      ? '예약된 캠페인입니다. Marketo에서 Email Program을 먼저 unapprove한 뒤 재시도하세요.'
      : '진행 중이거나 승인 대기 중인 캠페인은 완료 후 재시도하세요.';
    return Response.json(
      { success: false, error: `실행 불가: 현재 상태(${lockResult.currentStatus ?? '?'}). ${hint}` },
      { status: 409 }
    );
  }

  log(appDb, id, 'extract', 'running',
    BYPASS_LEADS.length > 0 ? '[우회 모드] 사내 DB 건너뜀 — INTERNAL_DB_BYPASS_LEADS 사용' : '사내 DB 대상자 추출 시작');

  try {
    // ── Step 1: DB 추출 (또는 우회 모드) ─────────────────────────
    let emails: string[];

    if (BYPASS_LEADS.length > 0) {
      // 우회 모드: 사내 DB 미연결 상태에서 Marketo 연동 테스트용
      // .env.local의 INTERNAL_DB_BYPASS_LEADS에 쉼표 구분 이메일 주소 설정
      emails = BYPASS_LEADS;
      log(appDb, id, 'extract', 'done', `[우회 모드] ${emails.length}명 사용: ${emails.join(', ')}`);
    } else {
      const filters: FilterCondition[] =
        typeof seg.filters === 'string' ? JSON.parse(seg.filters) : seg.filters;
      const { sql: whereClause, params: qParams } = buildWhereClause(filters, FIELD_DEFS);
      const emailSql = `SELECT \`${EMAIL_FIELD}\` AS email FROM \`${TABLE}\` WHERE ${whereClause}`;
      assertReadOnly(emailSql);

      const internalDb = getInternalDb();
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const [emailRows] = await internalDb.execute(emailSql, qParams as any[]);
      emails = (emailRows as { email: string }[]).map((r) => r.email).filter(Boolean);
      log(appDb, id, 'extract', 'done', `추출 완료: ${emails.length}명`);
    }

    appDb.prepare(`UPDATE campaigns SET lead_count=?, updated_at=? WHERE id=?`)
      .run(emails.length, new Date().toISOString(), id);

    // ── Step 2: Marketo 리드 업서트 ──────────────────────────────
    setStatus(appDb, id, 'uploading');
    log(appDb, id, 'upsert_leads', 'running', 'Marketo 리드 업서트 시작');
    const leadIds = await upsertLeads(emails);
    log(appDb, id, 'upsert_leads', 'done', `${leadIds.length}명 업서트 완료`);

    // ── Step 3: 고정 Static List 갱신 (기존 멤버 제거 → 새 리드 추가) ──
    setStatus(appDb, id, 'uploading');
    const audienceListId = parseInt(seg.marketo_audience_list_id, 10);
    log(appDb, id, 'list_refresh', 'running', `Static List(${audienceListId}) 갱신 시작`);

    const existingIds = await getListLeadIds(audienceListId);
    if (existingIds.length > 0) {
      await removeLeadsFromList(audienceListId, existingIds);
      log(appDb, id, 'list_refresh', 'running', `기존 멤버 ${existingIds.length}명 제거 완료`);
    }
    await addLeadsToList(audienceListId, leadIds);

    appDb.prepare(`UPDATE campaigns SET marketo_list_id=?, marketo_list_name=?, updated_at=? WHERE id=?`)
      .run(String(audienceListId), `Audience List ${audienceListId}`, new Date().toISOString(), id);
    log(appDb, id, 'list_refresh', 'done', `리스트 갱신 완료: ${leadIds.length}명 추가`);

    // ── Step 3.5: Email Program My Token 주입 ────────────────────────
    // setProgramMyTokens 성공 시 sendSampleEmail에서 Marketo가 토큰을 치환해 발송.
    // 실패해도 Phase 1을 멈추지 않음 — 테스트 메일이 raw 토큰으로 발송됨.
    const epId = parseInt(seg.marketo_email_program_id, 10);
    const epTokens = buildEpTokenPayload(asset, campaign);
    if (epTokens.length > 0) {
      log(appDb, id, 'set_ep_tokens', 'running', `My Token ${epTokens.length}개 EP(${epId})에 주입 중`);
      try {
        await setProgramMyTokens(epId, epTokens);
        log(appDb, id, 'set_ep_tokens', 'done', `My Token ${epTokens.length}개 설정 완료`);
      } catch (tokenErr) {
        const tokenMsg = tokenErr instanceof Error ? tokenErr.message : String(tokenErr);
        log(appDb, id, 'set_ep_tokens', 'error',
          `My Token 설정 실패 (테스트 메일은 raw 토큰으로 발송됨): ${tokenMsg}`);
      }
    }

    // ── Step 4: 테스트 메일 발송 ─────────────────────────────────
    setStatus(appDb, id, 'preparing');
    // asset.marketo_email_id를 직접 사용 (Email Program API /emailProgram/{id}.json 미지원 계정 대응)
    log(appDb, id, 'send_test_email', 'running', `테스트 메일 발송 → ${TEST_EMAILS.join(', ')}`);
    const emailIdRaw = asset.marketo_email_id;
    if (!emailIdRaw) {
      throw new Error('에셋에 Marketo Email ID가 설정되지 않았습니다. 에셋 라이브러리에서 "Marketo Email ID" 필드를 입력하세요.');
    }
    const emailId = parseInt(String(emailIdRaw), 10);
    if (isNaN(emailId)) {
      throw new Error(`에셋의 Marketo Email ID(${emailIdRaw})가 유효한 숫자가 아닙니다.`);
    }
    // Marketo sendSample은 수신자 1명씩만 지원 — 루프로 순차 발송
    for (const addr of TEST_EMAILS) {
      await sendSampleEmail(emailId, addr);
    }
    log(appDb, id, 'send_test_email', 'done', `테스트 메일 발송 완료 → ${TEST_EMAILS.join(', ')}`);

    // 승인 알림 이메일 발송 (실패해도 Phase 1 전체를 실패시키지 않음)
    try {
      const expiresAt = Date.now() + 72 * 60 * 60 * 1000;
      const baseUrl = process.env.APP_URL || 'http://localhost:3000';
      const approveToken = generateToken('approve', id, expiresAt);
      const rejectToken  = generateToken('reject',  id, expiresAt);
      const approveUrl = `${baseUrl}/api/campaigns/${id}/approve-via-link?token=${approveToken}&expires=${expiresAt}`;
      const rejectUrl  = `${baseUrl}/api/campaigns/${id}/reject-via-link?token=${rejectToken}&expires=${expiresAt}`;
      const updatedCampaign = appDb.prepare('SELECT * FROM campaigns WHERE id = ?').get(id) as Campaign;
      await sendApprovalEmail(updatedCampaign, approveUrl, rejectUrl);
      log(appDb, id, 'send_approval_email', 'done', `승인 알림 이메일 발송 → ${TEST_EMAILS.join(', ')}`);
    } catch (emailErr) {
      log(appDb, id, 'send_approval_email', 'error',
        `승인 알림 이메일 발송 실패 (Phase 1은 계속): ${emailErr instanceof Error ? emailErr.message : String(emailErr)}`);
    }

    // Phase 1 완료 — 담당자 승인 대기
    setStatus(appDb, id, 'awaiting_approval');
    return Response.json({
      success: true,
      data: { lead_count: emails.length, list_id: audienceListId, status: 'awaiting_approval' },
    });

  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    setStatus(appDb, id, 'failed', msg);
    log(appDb, id, 'error', 'error', msg);
    return Response.json({ success: false, error: msg }, { status: 500 });
  }
}
