# One-Click Approval Email Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Phase 1 완료 시 nodemailer SMTP로 알림 이메일을 발송하고, 이메일 내 버튼 클릭 → GET 확인 페이지 → POST 실제 승인/재검토 흐름을 구현한다.

**Architecture:** `lib/approval-mailer.ts`(신규)에서 HMAC-SHA256 무상태 토큰 생성/검증 + nodemailer 이메일 발송을 담당한다. `approve-via-link`/`reject-via-link`(신규) 라우트는 GET에서 확인 페이지를 보여주고, POST에서 내부 fetch로 기존 `/approve`·`/reject` 엔드포인트를 호출한다. `run/route.ts`는 테스트 메일 직후 try/catch로 알림 이메일을 발송하며, 실패해도 Phase 1을 중단하지 않는다.

**Tech Stack:** Next.js 16.2.4 App Router (params는 Promise → await 필수), nodemailer, Node.js crypto (내장), better-sqlite3

---

## File Map

| 파일 | 역할 |
|------|------|
| `lib/approval-mailer.ts` (신규) | HMAC 토큰 생성/검증, nodemailer 이메일 발송 |
| `app/api/campaigns/[id]/approve-via-link/route.ts` (신규) | GET → 확인 HTML, POST → 내부 approve 호출 |
| `app/api/campaigns/[id]/reject-via-link/route.ts` (신규) | GET → 확인 HTML, POST → 내부 reject 호출 |
| `app/api/campaigns/[id]/run/route.ts` (수정) | 테스트 메일 직후 sendApprovalEmail 호출 추가 |
| `package.json` (수정) | nodemailer + @types/nodemailer 추가 |

---

## Task 1: nodemailer 설치 + `lib/approval-mailer.ts` 생성

**Files:**
- Modify: `package.json`
- Create: `lib/approval-mailer.ts`

- [ ] **Step 1: nodemailer 설치**

  ```bash
  cd /Users/geonwoo/marketo-send-automation
  npm install nodemailer
  npm install -D @types/nodemailer
  ```

  예상: `package.json`의 `dependencies`에 `"nodemailer"`, `devDependencies`에 `"@types/nodemailer"` 추가됨.

- [ ] **Step 2: `lib/approval-mailer.ts` 생성**

  ```typescript
  /**
   * lib/approval-mailer.ts
   * 원클릭 승인 링크 — HMAC-SHA256 토큰 생성/검증 + 알림 이메일 발송
   */
  import crypto from 'crypto';
  import nodemailer from 'nodemailer';
  import type { Campaign } from '@/lib/types';

  const SECRET = process.env.APPROVAL_SECRET ?? '';

  if (!SECRET) {
    console.warn('[approval-mailer] APPROVAL_SECRET 환경변수가 설정되지 않았습니다. 승인 이메일 발송이 비활성화됩니다.');
  }

  const transporter = nodemailer.createTransport({
    host: process.env.SMTP_HOST,
    port: parseInt(process.env.SMTP_PORT ?? '587', 10),
    secure: false,
    auth: {
      user: process.env.SMTP_USER,
      pass: process.env.SMTP_PASS,
    },
  });

  const SMTP_FROM = process.env.SMTP_FROM || process.env.SMTP_USER || '';

  export function generateToken(action: string, campaignId: string, expiresAt: number): string {
    return crypto
      .createHmac('sha256', SECRET)
      .update(`${action}:${campaignId}:${expiresAt}`)
      .digest('hex');
  }

  export function verifyToken(action: string, campaignId: string, token: string, expires: string): boolean {
    if (!SECRET) return false;
    const expiresAt = parseInt(expires, 10);
    if (isNaN(expiresAt) || Date.now() > expiresAt) return false;
    const expected = generateToken(action, campaignId, expiresAt);
    try {
      return crypto.timingSafeEqual(Buffer.from(token, 'hex'), Buffer.from(expected, 'hex'));
    } catch {
      return false;
    }
  }

  function escapeHtml(str: string): string {
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  export async function sendApprovalEmail(
    campaign: Campaign,
    approveUrl: string,
    rejectUrl: string,
  ): Promise<void> {
    const toList = (process.env.SEND_TEST_EMAIL_TO || '')
      .split(',').map((e) => e.trim()).filter(Boolean);
    if (toList.length === 0) throw new Error('SEND_TEST_EMAIL_TO 환경변수가 설정되지 않았습니다.');

    const appUrl = process.env.APP_URL || 'http://localhost:3000';

    const html = `<!DOCTYPE html>
  <html lang="ko">
  <head><meta charset="utf-8"><title>승인 요청</title></head>
  <body style="font-family:sans-serif;max-width:560px;margin:40px auto;padding:0 16px;color:#1e293b">
    <h2 style="font-size:18px;margin-bottom:4px">[승인 필요] ${escapeHtml(campaign.name)}</h2>
    <p style="color:#64748b;font-size:13px;margin-top:0">테스트 메일을 확인하신 후 승인해주세요</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px">
      <tr><td style="padding:6px 0;color:#64748b;width:90px">캠페인</td><td style="padding:6px 0;font-weight:600">${escapeHtml(campaign.name)}</td></tr>
      <tr><td style="padding:6px 0;color:#64748b">세그먼트</td><td style="padding:6px 0">${escapeHtml(campaign.segment_name)}</td></tr>
      <tr><td style="padding:6px 0;color:#64748b">에셋</td><td style="padding:6px 0">${escapeHtml(campaign.asset_name)}</td></tr>
      <tr><td style="padding:6px 0;color:#64748b">보상 URL</td><td style="padding:6px 0;font-size:12px;word-break:break-all">${escapeHtml(campaign.reward_url)}</td></tr>
      <tr><td style="padding:6px 0;color:#64748b">RTZ 발송</td><td style="padding:6px 0">${escapeHtml(campaign.send_time || '-')}</td></tr>
      <tr><td style="padding:6px 0;color:#64748b">대상자</td><td style="padding:6px 0"><strong>${campaign.lead_count ?? 0}명</strong></td></tr>
    </table>
    <div style="margin:24px 0">
      <a href="${approveUrl}" style="display:inline-block;padding:12px 24px;background:#16a34a;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;margin-right:12px">✅ 발송 승인</a>
      <a href="${rejectUrl}" style="display:inline-block;padding:12px 24px;background:#fff;color:#475569;text-decoration:none;border-radius:8px;font-weight:600;border:1px solid #cbd5e1">🔄 재검토</a>
    </div>
    <p style="font-size:12px;color:#94a3b8">이 링크는 72시간 후 만료됩니다.<br>앱에서도 직접 처리할 수 있습니다: <a href="${appUrl}/campaigns/${campaign.id}">${appUrl}/campaigns/${campaign.id}</a></p>
  </body>
  </html>`;

    await transporter.sendMail({
      from: SMTP_FROM,
      to: toList.join(', '),
      subject: `[승인 필요] ${campaign.name} 테스트 메일 확인`,
      html,
    });
  }
  ```

- [ ] **Step 3: lint 통과 확인**

  ```bash
  cd /Users/geonwoo/marketo-send-automation && npm run lint 2>&1
  ```

  예상: 0 errors.

- [ ] **Step 4: 커밋**

  ```bash
  cd /Users/geonwoo/marketo-send-automation
  git add lib/approval-mailer.ts package.json package-lock.json
  git commit -m "feat: add approval-mailer — HMAC token + nodemailer SMTP

  Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
  ```

---

## Task 2: `app/api/campaigns/[id]/approve-via-link/route.ts` 생성

**Files:**
- Create: `app/api/campaigns/[id]/approve-via-link/route.ts`

이 라우트는:
- **GET**: 토큰 검증 후 확인 HTML 페이지 반환 (POST form 포함). 상태 변경 없음 (CSRF 방어).
- **POST**: form body에서 token/expires 읽어 재검증 후 내부 fetch로 `/api/campaigns/${id}/approve` 호출.

- [ ] **Step 1: 파일 생성**

  ```typescript
  /**
   * GET  /api/campaigns/[id]/approve-via-link?token=...&expires=...
   *   → 토큰 검증 → 확인 HTML 페이지 반환 (상태 변경 없음)
   *
   * POST /api/campaigns/[id]/approve-via-link  (form submit)
   *   → form body에서 token/expires 읽기 → 재검증 → 내부 approve 호출
   */
  import { NextRequest } from 'next/server';
  import { verifyToken } from '@/lib/approval-mailer';

  type Ctx = { params: Promise<{ id: string }> };

  const APP_URL = process.env.APP_URL || 'http://localhost:3000';

  function pageHtml(title: string, content: string): Response {
    return new Response(
      `<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${title}</title></head><body style="font-family:sans-serif;max-width:480px;margin:60px auto;padding:0 16px;color:#1e293b">${content}</body></html>`,
      { headers: { 'Content-Type': 'text/html; charset=utf-8' } },
    );
  }

  function expiredPage(id: string): Response {
    return pageHtml('링크 만료', `
      <h2 style="color:#dc2626">링크가 만료되었거나 유효하지 않습니다</h2>
      <p style="color:#475569">72시간이 경과했거나 잘못된 링크입니다. 앱에서 직접 처리해주세요.</p>
      <a href="${APP_URL}/campaigns/${id}" style="color:#2563eb;font-weight:600">앱에서 캠페인 보기 →</a>
    `);
  }

  export async function GET(req: NextRequest, { params }: Ctx) {
    const { id } = await params;
    const { searchParams } = new URL(req.url);
    const token = searchParams.get('token') ?? '';
    const expires = searchParams.get('expires') ?? '';

    if (!verifyToken('approve', id, token, expires)) {
      return expiredPage(id);
    }

    return pageHtml('발송 승인', `
      <h2>캠페인 발송을 승인하시겠습니까?</h2>
      <p style="color:#475569;font-size:15px">승인하면 Marketo Smart Campaign이 예약됩니다.</p>
      <form method="POST" action="/api/campaigns/${id}/approve-via-link">
        <input type="hidden" name="token" value="${token}">
        <input type="hidden" name="expires" value="${expires}">
        <div style="display:flex;gap:12px;margin-top:24px;flex-wrap:wrap">
          <button type="submit" style="padding:12px 24px;background:#16a34a;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer">예, 발송 승인합니다</button>
          <a href="${APP_URL}/campaigns/${id}" style="padding:12px 24px;background:#fff;color:#475569;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center">돌아가기</a>
        </div>
      </form>
    `);
  }

  export async function POST(req: NextRequest, { params }: Ctx) {
    const { id } = await params;
    const formData = await req.formData();
    const token = String(formData.get('token') ?? '');
    const expires = String(formData.get('expires') ?? '');

    if (!verifyToken('approve', id, token, expires)) {
      return expiredPage(id);
    }

    const res = await fetch(`${APP_URL}/api/campaigns/${id}/approve`, { method: 'POST' });

    if (res.ok) {
      return pageHtml('승인 완료', `
        <h2 style="color:#16a34a">승인되었습니다 ✅</h2>
        <p>캠페인이 Marketo에 예약되었습니다.</p>
        <a href="${APP_URL}/campaigns/${id}" style="display:inline-block;margin-top:12px;padding:10px 20px;background:#fff;color:#475569;border:1px solid #cbd5e1;border-radius:8px;text-decoration:none;font-weight:600">앱에서 캠페인 확인 →</a>
      `);
    }

    const errBody = await res.json().catch(() => ({ error: '알 수 없는 오류' }));
    const errMsg: string = typeof errBody === 'object' && errBody !== null && 'error' in errBody
      ? String((errBody as { error: unknown }).error)
      : '알 수 없는 오류';

    if (res.status === 409) {
      return pageHtml('이미 처리됨', `
        <h2 style="color:#d97706">이미 처리된 캠페인입니다</h2>
        <p style="color:#475569">${errMsg}</p>
        <a href="${APP_URL}/campaigns/${id}" style="color:#2563eb;font-weight:600">앱에서 캠페인 확인 →</a>
      `);
    }

    return pageHtml('오류', `
      <h2 style="color:#dc2626">오류가 발생했습니다</h2>
      <p style="color:#475569">${errMsg}</p>
      <a href="${APP_URL}/campaigns/${id}" style="color:#2563eb;font-weight:600">앱에서 직접 처리해주세요 →</a>
    `);
  }
  ```

- [ ] **Step 2: lint 통과 확인**

  ```bash
  cd /Users/geonwoo/marketo-send-automation && npm run lint 2>&1
  ```

  예상: 0 errors.

- [ ] **Step 3: 커밋**

  ```bash
  cd /Users/geonwoo/marketo-send-automation
  git add app/api/campaigns/\[id\]/approve-via-link/route.ts
  git commit -m "feat: add approve-via-link route — GET confirmation + POST approve

  Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
  ```

---

## Task 3: `app/api/campaigns/[id]/reject-via-link/route.ts` 생성

**Files:**
- Create: `app/api/campaigns/[id]/reject-via-link/route.ts`

approve-via-link와 동일한 GET(확인 페이지) / POST(실제 처리) 구조. action = `"reject"`.

- [ ] **Step 1: 파일 생성**

  ```typescript
  /**
   * GET  /api/campaigns/[id]/reject-via-link?token=...&expires=...
   *   → 토큰 검증 → 재검토 확인 HTML 반환 (상태 변경 없음)
   *
   * POST /api/campaigns/[id]/reject-via-link  (form submit)
   *   → form body에서 token/expires 읽기 → 재검증 → 내부 reject 호출
   */
  import { NextRequest } from 'next/server';
  import { verifyToken } from '@/lib/approval-mailer';

  type Ctx = { params: Promise<{ id: string }> };

  const APP_URL = process.env.APP_URL || 'http://localhost:3000';

  function pageHtml(title: string, content: string): Response {
    return new Response(
      `<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${title}</title></head><body style="font-family:sans-serif;max-width:480px;margin:60px auto;padding:0 16px;color:#1e293b">${content}</body></html>`,
      { headers: { 'Content-Type': 'text/html; charset=utf-8' } },
    );
  }

  function expiredPage(id: string): Response {
    return pageHtml('링크 만료', `
      <h2 style="color:#dc2626">링크가 만료되었거나 유효하지 않습니다</h2>
      <p style="color:#475569">72시간이 경과했거나 잘못된 링크입니다. 앱에서 직접 처리해주세요.</p>
      <a href="${APP_URL}/campaigns/${id}" style="color:#2563eb;font-weight:600">앱에서 캠페인 보기 →</a>
    `);
  }

  export async function GET(req: NextRequest, { params }: Ctx) {
    const { id } = await params;
    const { searchParams } = new URL(req.url);
    const token = searchParams.get('token') ?? '';
    const expires = searchParams.get('expires') ?? '';

    if (!verifyToken('reject', id, token, expires)) {
      return expiredPage(id);
    }

    return pageHtml('재검토 요청', `
      <h2>재검토를 요청하시겠습니까?</h2>
      <p style="color:#475569;font-size:15px">재검토를 요청하면 캠페인이 draft 상태로 돌아갑니다.<br>앱에서 수정 후 Phase 1을 다시 실행하세요.</p>
      <form method="POST" action="/api/campaigns/${id}/reject-via-link">
        <input type="hidden" name="token" value="${token}">
        <input type="hidden" name="expires" value="${expires}">
        <div style="display:flex;gap:12px;margin-top:24px;flex-wrap:wrap">
          <button type="submit" style="padding:12px 24px;background:#d97706;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer">예, 재검토 요청합니다</button>
          <a href="${APP_URL}/campaigns/${id}" style="padding:12px 24px;background:#fff;color:#475569;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center">돌아가기</a>
        </div>
      </form>
    `);
  }

  export async function POST(req: NextRequest, { params }: Ctx) {
    const { id } = await params;
    const formData = await req.formData();
    const token = String(formData.get('token') ?? '');
    const expires = String(formData.get('expires') ?? '');

    if (!verifyToken('reject', id, token, expires)) {
      return expiredPage(id);
    }

    const res = await fetch(`${APP_URL}/api/campaigns/${id}/reject`, { method: 'POST' });

    if (res.ok) {
      return pageHtml('재검토 요청 완료', `
        <h2 style="color:#d97706">재검토가 요청되었습니다 🔄</h2>
        <p>캠페인이 draft 상태로 돌아갔습니다.</p>
        <p style="color:#475569;font-size:14px;margin-top:4px">다음 단계: 앱에서 캠페인을 수정한 후 Phase 1을 다시 실행하세요.</p>
        <a href="${APP_URL}/campaigns/${id}" style="display:inline-block;margin-top:16px;padding:10px 20px;background:#fff;color:#475569;border:1px solid #cbd5e1;border-radius:8px;text-decoration:none;font-weight:600">앱에서 캠페인 수정하기 →</a>
      `);
    }

    const errBody = await res.json().catch(() => ({ error: '알 수 없는 오류' }));
    const errMsg: string = typeof errBody === 'object' && errBody !== null && 'error' in errBody
      ? String((errBody as { error: unknown }).error)
      : '알 수 없는 오류';

    if (res.status === 409) {
      return pageHtml('이미 처리됨', `
        <h2 style="color:#d97706">이미 처리된 캠페인입니다</h2>
        <p style="color:#475569">${errMsg}</p>
        <a href="${APP_URL}/campaigns/${id}" style="color:#2563eb;font-weight:600">앱에서 캠페인 확인 →</a>
      `);
    }

    return pageHtml('오류', `
      <h2 style="color:#dc2626">오류가 발생했습니다</h2>
      <p style="color:#475569">${errMsg}</p>
      <a href="${APP_URL}/campaigns/${id}" style="color:#2563eb;font-weight:600">앱에서 직접 처리해주세요 →</a>
    `);
  }
  ```

- [ ] **Step 2: lint 통과 확인**

  ```bash
  cd /Users/geonwoo/marketo-send-automation && npm run lint 2>&1
  ```

  예상: 0 errors.

- [ ] **Step 3: 커밋**

  ```bash
  cd /Users/geonwoo/marketo-send-automation
  git add app/api/campaigns/\[id\]/reject-via-link/route.ts
  git commit -m "feat: add reject-via-link route — GET confirmation + POST reject

  Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
  ```

---

## Task 4: `run/route.ts` 수정 — 테스트 메일 직후 sendApprovalEmail 호출

**Files:**
- Modify: `app/api/campaigns/[id]/run/route.ts`

- [ ] **Step 1: import 추가**

  기존 import 블록 끝에 (line ~26 `import { v4 as uuid }` 아래) 삽입:

  현재:
  ```typescript
  import {
    upsertLeads, addLeadsToList,
    getListLeadIds, removeLeadsFromList,
    sendSampleEmail,
  } from '@/lib/marketo';
  import { v4 as uuid } from 'uuid';
  import { Campaign, Segment, AssetLibraryItem, FilterCondition } from '@/lib/types';
  ```

  변경 후:
  ```typescript
  import {
    upsertLeads, addLeadsToList,
    getListLeadIds, removeLeadsFromList,
    sendSampleEmail,
  } from '@/lib/marketo';
  import { v4 as uuid } from 'uuid';
  import { Campaign, Segment, AssetLibraryItem, FilterCondition } from '@/lib/types';
  import { generateToken, sendApprovalEmail } from '@/lib/approval-mailer';
  ```

- [ ] **Step 2: sendApprovalEmail 호출 블록 삽입**

  `log(appDb, id, 'send_test_email', 'done', ...)` 직후, `setStatus(appDb, id, 'awaiting_approval')` 직전에 삽입.

  현재 (line ~233):
  ```typescript
      log(appDb, id, 'send_test_email', 'done', `테스트 메일 발송 완료 → ${TEST_EMAILS.join(', ')}`);

      // Phase 1 완료 — 담당자 승인 대기
      setStatus(appDb, id, 'awaiting_approval');
  ```

  변경 후:
  ```typescript
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
  ```

- [ ] **Step 3: lint 통과 확인**

  ```bash
  cd /Users/geonwoo/marketo-send-automation && npm run lint 2>&1
  ```

  예상: 0 errors.

- [ ] **Step 4: 커밋**

  ```bash
  cd /Users/geonwoo/marketo-send-automation
  git add app/api/campaigns/\[id\]/run/route.ts
  git commit -m "feat: send approval notification email after Phase 1 test send

  - try/catch wrapping: SMTP failure does not abort Phase 1
  - 72hr HMAC token; approve/reject URLs point to via-link routes

  Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
  ```

---

## 검증 체크리스트 (수동)

구현 완료 후 수동으로 확인:

- [ ] `.env.local`에 `APPROVAL_SECRET`, `APP_URL`, `SMTP_*` 환경변수 설정
- [ ] 캠페인 Phase 1 실행 → `send_approval_email done` 로그 확인 (job_logs)
- [ ] SMTP 수신함에 `[승인 필요]` 이메일 수신 확인
- [ ] 이메일 `[✅ 발송 승인]` 버튼 클릭 → 확인 페이지 로드 (캠페인명 표시)
- [ ] 확인 페이지에서 `[예, 발송 승인합니다]` 클릭 → "승인되었습니다" 페이지 + 앱 링크
- [ ] 앱에서 캠페인 상태 `scheduled` 확인
- [ ] 이미 승인된 캠페인 링크 재클릭 → 확인 페이지 GET → POST → "이미 처리된 캠페인" 페이지
- [ ] 이메일 `[🔄 재검토]` 버튼 클릭 → 재검토 확인 페이지
- [ ] `[예, 재검토 요청합니다]` 클릭 → "재검토가 요청되었습니다" 페이지 + 다음 액션 안내
- [ ] 앱에서 캠페인 상태 `draft` 확인
- [ ] 만료된 토큰 링크 클릭 (또는 `APPROVAL_SECRET` 다른 값으로 테스트) → "링크가 만료되었거나 유효하지 않습니다" 페이지
- [ ] SMTP 설정 없을 때: Phase 1은 계속 진행, `send_approval_email error` 로그만 기록
