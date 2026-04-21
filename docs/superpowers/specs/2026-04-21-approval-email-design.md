# Design: 테스트 메일 원클릭 승인 링크

**Date:** 2026-04-21
**Status:** Approved
**Scope:** `lib/approval-mailer.ts` (신규), `app/api/campaigns/[id]/approve-via-link/route.ts` (신규), `app/api/campaigns/[id]/reject-via-link/route.ts` (신규), `app/api/campaigns/[id]/run/route.ts` (수정), `package.json` (수정)

---

## Context

Phase 1 완료 후 실무자는 테스트 메일을 수신하고 앱을 다시 열어 [발송 승인]을 클릭해야 한다. 이메일 클라이언트에서 직접 승인/재검토를 처리할 수 없어 불필요한 왕복이 발생한다. 특히 모바일 환경에서 앱 재접속이 번거롭다.

**핵심 제약:** `sendSampleEmail` (Marketo)은 템플릿을 그대로 발송하므로 동적 콘텐츠를 주입할 수 없다. 따라서 Marketo 테스트 메일 내에 승인 링크를 직접 삽입하는 것은 불가능하다.

---

## Goal

- Phase 1 완료 시 **별도 알림 이메일**을 발송 (Marketo 테스트 메일과 별개)
- 알림 이메일에 [발송 승인] / [재검토] 원클릭 버튼 포함
- 링크 클릭 → 토큰 검증 → 기존 approve/reject 엔드포인트 호출 → HTML 결과 페이지 반환
- 신규 DB 스키마 변경 없음 (HMAC 토큰으로 무상태 인증)

---

## Design

### 토큰 구조 (HMAC-SHA256, 무상태)

```
expiresAt = Date.now() + 48 * 60 * 60 * 1000   (48시간 후, unix ms)
signature = HMAC-SHA256(APPROVAL_SECRET, "${action}:${campaignId}:${expiresAt}")
URL = ${APP_URL}/api/campaigns/${id}/${action}-via-link?token=${signature}&expires=${expiresAt}
```

- `action`: `"approve"` 또는 `"reject"`
- DB 저장 불필요 — 검증 시 HMAC 재계산으로 확인
- 만료 후 클릭 → 에러 HTML 반환 (재발송 안내)

### 데이터 흐름

```
Phase 1 완료 (awaiting_approval)
  → sendApprovalEmail(campaign, approveUrl, rejectUrl)
        → nodemailer SMTP 발송
              ↓ (이메일 수신)
실무자: [발송 승인] 클릭
  → GET /api/campaigns/${id}/approve-via-link?token=...&expires=...
        → verifyToken 검증
        → POST /api/campaigns/${id}/approve (내부 호출)
        → 결과 HTML 반환
```

---

## Files

### 신규: `lib/approval-mailer.ts`

두 가지 역할:
1. **토큰 생성/검증** — `generateToken(action, id, expiresAt)`, `verifyToken(action, id, token, expires)`
2. **이메일 발송** — `sendApprovalEmail(campaign, approveUrl, rejectUrl)` (nodemailer)

nodemailer transporter는 모듈 로드 시 한 번 생성 (환경변수 기반).

```typescript
// 환경변수
APPROVAL_SECRET   // HMAC 서명 키 (필수)
APP_URL           // 앱 베이스 URL (예: http://localhost:3000)
SMTP_HOST         // SMTP 서버 (예: smtp.gmail.com)
SMTP_PORT         // 포트 (기본: 587)
SMTP_USER         // SMTP 사용자명
SMTP_PASS         // SMTP 비밀번호 (Gmail: 앱 비밀번호)
SMTP_FROM         // 발신자 주소 (기본: SMTP_USER)
```

### 신규: `app/api/campaigns/[id]/approve-via-link/route.ts`

GET handler:
1. `verifyToken("approve", id, token, expires)` — 실패 시 에러 HTML 반환
2. `fetch(POST /api/campaigns/${id}/approve)` 내부 호출
3. 성공/실패 HTML 페이지 반환 (앱 URL 링크 포함)

### 신규: `app/api/campaigns/[id]/reject-via-link/route.ts`

GET handler — approve-via-link와 동일 구조, action = `"reject"`.

### 수정: `app/api/campaigns/[id]/run/route.ts`

`send_test_email` 단계 완료 직후, `setStatus(awaiting_approval)` 호출 직전:

```typescript
// 승인 알림 이메일 발송 (실패해도 Phase 1 전체를 실패시키지 않음)
try {
  const expiresAt = Date.now() + 48 * 60 * 60 * 1000;
  const baseUrl = process.env.APP_URL || 'http://localhost:3000';
  const approveToken = generateToken('approve', id, expiresAt);
  const rejectToken  = generateToken('reject',  id, expiresAt);
  const approveUrl = `${baseUrl}/api/campaigns/${id}/approve-via-link?token=${approveToken}&expires=${expiresAt}`;
  const rejectUrl  = `${baseUrl}/api/campaigns/${id}/reject-via-link?token=${rejectToken}&expires=${expiresAt}`;
  await sendApprovalEmail(updatedCampaign, approveUrl, rejectUrl);
  log(appDb, id, 'send_approval_email', 'done', `승인 알림 이메일 발송 → ${TEST_EMAILS.join(', ')}`);
} catch (emailErr) {
  log(appDb, id, 'send_approval_email', 'error',
    `승인 알림 이메일 발송 실패 (Phase 1은 계속): ${emailErr instanceof Error ? emailErr.message : String(emailErr)}`);
}
```

`updatedCampaign`은 `lead_count`가 반영된 최신 캠페인 데이터. DB에서 재조회한다.

### 수정: `package.json`

```bash
npm install nodemailer
npm install -D @types/nodemailer
```

---

## 이메일 HTML 구조

```
제목: [승인 필요] {campaign.name} 테스트 메일 확인

─────────────────────────────────
테스트 메일을 확인하신 후 승인해주세요
─────────────────────────────────
캠페인:   {name}
세그먼트: {segment_name}
에셋:     {asset_name}
보상 URL: {reward_url}
RTZ 발송: {send_time}
대상자:   {lead_count}명
─────────────────────────────────
[ ✅  발송 승인 ]    [ 🔄  재검토 ]
─────────────────────────────────
이 링크는 48시간 후 만료됩니다.
앱에서도 직접 처리할 수 있습니다: {APP_URL}/campaigns/{id}
```

---

## Edge Cases

| 케이스 | 처리 |
|--------|------|
| APPROVAL_SECRET 미설정 | 서버 시작 시 console.warn; 이메일 발송 스킵 |
| SMTP 설정 미설정 | `sendApprovalEmail` 에서 throw → Phase 1에서 catch 후 log만 기록 (Phase 1 계속) |
| 만료된 토큰 클릭 | "링크가 만료되었습니다. {APP_URL}/campaigns/{id} 에서 직접 처리하세요." HTML 반환 |
| 이미 승인된 캠페인에 클릭 | approve 내부 호출 → 409 → "이미 처리된 캠페인입니다." HTML 반환 |
| 동일 링크 두 번 클릭 | 두 번째 클릭 시 위와 동일 — 409 |
| APP_URL 미설정 | `http://localhost:3000` fallback |

---

## 새 환경변수 (.env.local 추가 필요)

```bash
# 원클릭 승인 링크
APPROVAL_SECRET=your-random-secret-key  # 필수
APP_URL=http://localhost:3000            # 또는 배포 URL

# SMTP (Gmail 예시)
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your@gmail.com
SMTP_PASS=xxxx-xxxx-xxxx-xxxx           # Gmail 앱 비밀번호
SMTP_FROM=your@gmail.com                # 선택, 기본값 SMTP_USER
```

---

## Verification

1. `.env.local`에 SMTP 설정 + `APPROVAL_SECRET` + `APP_URL` 추가
2. 캠페인 즉시 실행 → Phase 1 완료 → 이메일 수신함 확인
3. 알림 이메일 내 [발송 승인] 클릭 → "승인되었습니다" HTML + 앱 링크 확인
4. 앱에서 캠페인 상태가 `scheduled`로 변경됨 확인
5. [재검토] 클릭 → "재검토가 요청되었습니다" HTML 확인, 상태 `draft` 확인
6. 만료된 토큰 링크 클릭 → 에러 메시지 HTML 확인
