# RTZ + 테스트 메일 토큰 치환 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `segments.marketo_email_program_id`(Email Program ID)를 추가하여 Phase 2에서 Email Program API로 RTZ 발송을 활성화하고, Phase 1 테스트 메일 발송 전 `setProgramMyTokens`로 My Token을 사전 주입해 토큰이 치환된 상태로 테스트 메일이 발송되게 한다.

**Architecture:** segments 테이블에 `marketo_email_program_id` 컬럼을 추가(SC ID인 `marketo_program_id`는 호환성 유지). Phase 1(run)에서 `setProgramMyTokens(epId)` 후 `sendSampleEmail` 호출. Phase 2(approve)에서 `scheduleCampaign`(SC API) 전체를 `unapprove → setProgramMyTokens → scheduleEmailProgram(RTZ=true) → approveEmailProgram` 흐름으로 대체. cancel/route.ts는 `unapproveEmailProgram(epId)`로 실제 API 취소. campaigns 테이블에도 `marketo_email_program_id` 컬럼 추가해 approve 후 EP ID를 기록, cancel이 이를 참조한다.

**Tech Stack:** Next.js App Router, TypeScript, better-sqlite3, Marketo REST API (`/rest/asset/v1/emailProgram`, `/rest/asset/v1/program/{id}/tokens.json`)

---

## 파일 맵

| 파일 | 변경 유형 | 역할 |
|------|----------|------|
| `db/sqlite.ts` | Modify | segments + campaigns 마이그레이션 |
| `lib/types.ts` | Modify | Segment·Campaign 타입에 필드 추가 |
| `lib/marketo.ts` | Modify | unapprove 에러처리, scheduleEmailProgram RTZ, buildEpTokenPayload 헬퍼 |
| `components/segment-form.tsx` | Modify | EP ID 텍스트 입력 필드 추가 |
| `app/api/segments/route.ts` | Modify | POST 핸들러 신규 필드 저장 |
| `app/api/segments/[id]/route.ts` | Modify | PUT 핸들러 신규 필드 저장 |
| `app/api/campaigns/[id]/run/route.ts` | Modify | Phase 1: EP ID 검증 게이트 + 토큰 주입 |
| `app/api/campaigns/[id]/approve/route.ts` | Modify | Phase 2: EP 스케줄 전환 (SC → EP+RTZ) |
| `app/api/campaigns/[id]/cancel/route.ts` | Modify | EP unapprove API 취소로 전환 |

---

## Task 1: DB 스키마 + 타입 업데이트

**Files:**
- Modify: `db/sqlite.ts` (migrations 배열)
- Modify: `lib/types.ts` (Segment, Campaign 인터페이스)

- [ ] **Step 1: `db/sqlite.ts` — migrations 배열에 두 줄 추가**

`initSchema` 함수의 `migrations` 배열 끝부분(마지막 `recurring_send_time` 항목 뒤)에 추가:

```typescript
// 기존 마지막 줄 (참고용, 수정하지 않음)
`ALTER TABLE segments ADD COLUMN recurring_send_time TEXT DEFAULT '10:00'`,
// ↓↓ 이 아래에 추가
`ALTER TABLE segments ADD COLUMN marketo_email_program_id TEXT DEFAULT ''`,
`ALTER TABLE campaigns ADD COLUMN marketo_email_program_id TEXT`,
```

- [ ] **Step 2: `lib/types.ts` — Segment 인터페이스에 필드 추가**

`Segment` 인터페이스의 `marketo_audience_list_id` 줄 바로 아래에 추가:

```typescript
  marketo_email_program_id: string;   // Marketo Email Program ID — RTZ 스케줄 + My Token 주입용
```

- [ ] **Step 3: `lib/types.ts` — Campaign 인터페이스에 필드 추가**

`Campaign` 인터페이스의 `marketo_cloned_email_id` 줄 바로 아래에 추가:

```typescript
  marketo_email_program_id: string | null;  // Phase 2 후 EP ID 기록 — cancel 참조용
```

- [ ] **Step 4: 앱 기동 후 마이그레이션 확인**

```bash
cd /Users/geonwoo/marketo-send-automation
npm run dev
```

로그에 오류 없이 기동되면 마이그레이션 성공 (SQLite `ALTER TABLE`은 이미 존재하면 catch 후 무시).

- [ ] **Step 5: 커밋**

```bash
git add db/sqlite.ts lib/types.ts
git commit -m "feat: add marketo_email_program_id to segments and campaigns schema"
```

---

## Task 2: `lib/marketo.ts` — API 유틸리티 개선

**Files:**
- Modify: `lib/marketo.ts`

- [ ] **Step 1: `unapproveEmailProgram` 에러 처리 강화**

현재 코드(348~354행)를 아래로 교체:

```typescript
/** Email Program Unapprove — 702(이미 unapproved)/709(not found)만 무시, 나머지는 re-throw */
export async function unapproveEmailProgram(programId: number): Promise<void> {
  try {
    await mkRequest('POST', `/rest/asset/v1/emailProgram/${programId}/unapprove.json`);
  } catch (err) {
    if (err instanceof Error && /Marketo error (702|709):/.test(err.message)) return;
    throw err;
  }
}
```

- [ ] **Step 2: `scheduleEmailProgram`에 `recipientTimeZone` 파라미터 추가**

현재 코드(330~340행)를 아래로 교체:

```typescript
/** Email Program 스케줄 설정
 *  recipientTimeZone=true 시 수신자 현지 시간 기준 발송 (RTZ) */
export async function scheduleEmailProgram(
  programId: number,
  startDate: string,   // "YYYY-MM-DD"
  startTime: string,   // "HH:MM:SS"
  recipientTimeZone = false
): Promise<void> {
  const body: Record<string, unknown> = { startDate, startTime };
  if (recipientTimeZone) body.recipientTimeZone = true;
  await mkRequest(
    'PUT',
    `/rest/asset/v1/emailProgram/${programId}/schedule.json`,
    body
  );
}
```

- [ ] **Step 3: `buildEpTokenPayload` 헬퍼 추출 및 export**

`lib/marketo.ts` 상단 import에 AssetLibraryItem·Campaign 타입 추가:

```typescript
import type { MarketoEmailItem, AssetLibraryItem, Campaign } from './types';
```

파일 맨 아래(export 함수들 뒤)에 추가:

```typescript
// ────────────────────────────────────────────────────
// Email Program Token 페이로드 빌더
// ────────────────────────────────────────────────────

/**
 * 에셋 + 캠페인 데이터를 Marketo Program My Token API 페이로드로 변환.
 * SC schedule tokens({{my.xxx}}) → EP My Token API(my.xxx, type 포함)
 */
export function buildEpTokenPayload(
  asset: AssetLibraryItem,
  campaign: Campaign
): { name: string; value: string; type: string }[] {
  const strip = (t: string) => t.replace(/^\{\{/, '').replace(/\}\}$/, '');
  const out: { name: string; value: string; type: string }[] = [];

  if (asset.marketo_token_reward_url && campaign.reward_url)
    out.push({ name: strip(asset.marketo_token_reward_url), value: campaign.reward_url, type: 'URL' });
  if (asset.marketo_token_image && asset.image_url)
    out.push({ name: strip(asset.marketo_token_image), value: asset.image_url, type: 'URL' });
  if (asset.marketo_token_subject && asset.subject)
    out.push({ name: strip(asset.marketo_token_subject), value: asset.subject, type: 'Text' });
  if (asset.marketo_token_emoji && asset.emoji)
    out.push({ name: strip(asset.marketo_token_emoji), value: asset.emoji, type: 'Text' });
  if (asset.marketo_token_preheader && asset.preheader)
    out.push({ name: strip(asset.marketo_token_preheader), value: asset.preheader, type: 'Text' });
  if (asset.marketo_token_body && asset.body_text)
    out.push({ name: strip(asset.marketo_token_body), value: asset.body_text, type: 'Text' });

  return out;
}
```

- [ ] **Step 4: TypeScript 컴파일 확인**

```bash
npx tsc --noEmit 2>&1 | head -30
```

오류 없으면 OK.

- [ ] **Step 5: 커밋**

```bash
git add lib/marketo.ts
git commit -m "feat: add RTZ param to scheduleEmailProgram, fix unapprove error handling, add buildEpTokenPayload"
```

---

## Task 3: Segment API — 신규 필드 저장

**Files:**
- Modify: `app/api/segments/route.ts`
- Modify: `app/api/segments/[id]/route.ts`

- [ ] **Step 1: `app/api/segments/route.ts` — POST 핸들러 수정**

구조분해 줄을:

```typescript
const { name, description = '', filters = [], marketo_program_id = '', marketo_audience_list_id = '', is_recurring = 0, send_day_of_week = 1, recurring_send_time = '10:00' } = body;
```

아래로 교체:

```typescript
const {
  name,
  description = '',
  filters = [],
  marketo_program_id = '',
  marketo_audience_list_id = '',
  marketo_email_program_id = '',
  is_recurring = 0,
  send_day_of_week = 1,
  recurring_send_time = '10:00',
} = body;
```

INSERT 쿼리를:

```typescript
  db.prepare(`
    INSERT INTO segments (id, name, description, filters, marketo_program_id, marketo_audience_list_id, is_recurring, send_day_of_week, recurring_send_time, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(id, name, description, JSON.stringify(filters), marketo_program_id, marketo_audience_list_id, is_recurring, send_day_of_week, recurring_send_time, now, now);
```

아래로 교체:

```typescript
  db.prepare(`
    INSERT INTO segments (id, name, description, filters, marketo_program_id, marketo_audience_list_id, marketo_email_program_id, is_recurring, send_day_of_week, recurring_send_time, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(id, name, description, JSON.stringify(filters), marketo_program_id, marketo_audience_list_id, marketo_email_program_id, is_recurring, send_day_of_week, recurring_send_time, now, now);
```

- [ ] **Step 2: `app/api/segments/[id]/route.ts` — PUT 핸들러 수정**

구조분해 줄을:

```typescript
  const { name, description, filters, marketo_program_id, marketo_audience_list_id, is_recurring, send_day_of_week, recurring_send_time } = body;
```

아래로 교체:

```typescript
  const {
    name,
    description,
    filters,
    marketo_program_id,
    marketo_audience_list_id,
    marketo_email_program_id,
    is_recurring,
    send_day_of_week,
    recurring_send_time,
  } = body;
```

UPDATE 쿼리를:

```typescript
  db.prepare(`
    UPDATE segments SET name=?, description=?, filters=?, marketo_program_id=?, marketo_audience_list_id=?, is_recurring=?, send_day_of_week=?, recurring_send_time=?, updated_at=? WHERE id=?
  `).run(name, description ?? '', JSON.stringify(filters ?? []), marketo_program_id ?? '', marketo_audience_list_id ?? '', is_recurring ?? 0, send_day_of_week ?? 1, recurring_send_time ?? '10:00', now, id);
```

아래로 교체:

```typescript
  db.prepare(`
    UPDATE segments SET
      name=?, description=?, filters=?,
      marketo_program_id=?, marketo_audience_list_id=?, marketo_email_program_id=?,
      is_recurring=?, send_day_of_week=?, recurring_send_time=?,
      updated_at=?
    WHERE id=?
  `).run(
    name,
    description ?? '',
    JSON.stringify(filters ?? []),
    marketo_program_id ?? '',
    marketo_audience_list_id ?? '',
    marketo_email_program_id ?? '',
    is_recurring ?? 0,
    send_day_of_week ?? 1,
    recurring_send_time ?? '10:00',
    now,
    id
  );
```

- [ ] **Step 3: TypeScript 확인**

```bash
npx tsc --noEmit 2>&1 | head -30
```

- [ ] **Step 4: 커밋**

```bash
git add app/api/segments/route.ts app/api/segments/[id]/route.ts
git commit -m "feat: add marketo_email_program_id field to segment CRUD APIs"
```

---

## Task 4: Segment Form UI — EP ID 입력 필드

**Files:**
- Modify: `components/segment-form.tsx`

- [ ] **Step 1: state 추가**

`const [marketoAudienceListId, ...]` 줄 바로 아래에 추가:

```typescript
  const [marketoEmailProgramId, setMarketoEmailProgramId] = useState(initialData?.marketo_email_program_id ?? '');
```

- [ ] **Step 2: handleSave body에 필드 추가**

`handleSave`의 fetch body JSON에서:

```typescript
body: JSON.stringify({ name, description, filters, marketo_program_id: marketoProgramId, marketo_audience_list_id: marketoAudienceListId, is_recurring: isRecurring ? 1 : 0, send_day_of_week: sendDayOfWeek, recurring_send_time: recurringTime }),
```

아래로 교체:

```typescript
body: JSON.stringify({
  name,
  description,
  filters,
  marketo_program_id: marketoProgramId,
  marketo_audience_list_id: marketoAudienceListId,
  marketo_email_program_id: marketoEmailProgramId,
  is_recurring: isRecurring ? 1 : 0,
  send_day_of_week: sendDayOfWeek,
  recurring_send_time: recurringTime,
}),
```

- [ ] **Step 3: UI 입력 필드 추가**

`<MarketoSelect label="Audience Static List" .../>` 블록(pairHint 포함) 바로 아래에 추가:

```tsx
          <Input
            label="Email Program ID"
            placeholder="예: 713"
            value={marketoEmailProgramId}
            onChange={(e) => setMarketoEmailProgramId(e.target.value)}
            hint="Marketing Activities의 Email Program 숫자 ID. RTZ 발송 예약 및 My Token 주입에 사용됩니다."
          />
```

- [ ] **Step 4: 앱 기동 후 세그먼트 폼 UI 확인**

브라우저에서 `/segments/new` 접속 → "Email Program ID" 필드 노출 여부 확인.

- [ ] **Step 5: 커밋**

```bash
git add components/segment-form.tsx
git commit -m "feat: add Email Program ID field to segment form"
```

---

## Task 5: Phase 1 — 테스트 메일 토큰 주입 (`run/route.ts`)

**Files:**
- Modify: `app/api/campaigns/[id]/run/route.ts`

- [ ] **Step 1: import에 `buildEpTokenPayload`, `setProgramMyTokens` 추가**

기존 import:

```typescript
import {
  upsertLeads, addLeadsToList,
  getListLeadIds, removeLeadsFromList,
  sendSampleEmail,
} from '@/lib/marketo';
```

아래로 교체:

```typescript
import {
  upsertLeads, addLeadsToList,
  getListLeadIds, removeLeadsFromList,
  sendSampleEmail, setProgramMyTokens, buildEpTokenPayload,
} from '@/lib/marketo';
```

- [ ] **Step 2: 필수 필드 검증 게이트에 EP ID 추가**

기존 검증 블록(75행 근처의 `marketo_program_id` 체크 뒤)에 추가:

```typescript
  if (!seg.marketo_email_program_id) {
    return Response.json(
      { success: false, error: '세그먼트에 Email Program ID가 설정되지 않았습니다. 세그먼트 설정에서 "Email Program ID"를 입력하세요.' },
      { status: 400 }
    );
  }
```

- [ ] **Step 3: 테스트 메일 발송 전 My Token 주입 삽입**

Phase 1 Step 4 코드 블록에서 기존:

```typescript
    log(appDb, id, 'send_test_email', 'running', `테스트 메일 발송 → ${TEST_EMAILS.join(', ')}`);
    const emailIdRaw = asset.marketo_email_id;
```

앞에 다음 블록을 삽입:

```typescript
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
```

- [ ] **Step 4: TypeScript 확인**

```bash
npx tsc --noEmit 2>&1 | head -30
```

- [ ] **Step 5: 커밋**

```bash
git add app/api/campaigns/[id]/run/route.ts
git commit -m "feat: inject My Tokens before test email in Phase 1"
```

---

## Task 6: Phase 2 — Email Program RTZ 스케줄 (`approve/route.ts`)

**Files:**
- Modify: `app/api/campaigns/[id]/approve/route.ts`

- [ ] **Step 1: import 교체**

기존 import:

```typescript
import { scheduleCampaign } from '@/lib/marketo';
```

아래로 교체:

```typescript
import {
  unapproveEmailProgram,
  setProgramMyTokens,
  scheduleEmailProgram,
  approveEmailProgram,
  buildEpTokenPayload,
} from '@/lib/marketo';
```

- [ ] **Step 2: EP ID 검증 추가**

`programId` 계산 코드(현재 `const programId = parseInt(seg.marketo_program_id, 10);`) 전체를 아래로 교체:

```typescript
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
```

- [ ] **Step 3: `startTime` 정규화 블록 유지 + RTZ용 HH:MM 파생**

기존 startDate/startTime 파싱 블록(69~74행) 바로 뒤에 추가:

```typescript
  // RTZ 모드: HH:MM:SS 중 초(:SS) 제거 — 일부 Marketo 계정이 HH:MM만 허용
  const scheduleTime = startTime.slice(0, 5); // "HH:MM"
```

- [ ] **Step 4: try 블록 내부 전체 교체**

현재 try 블록 안의 SC 스케줄 코드:

```typescript
  try {
    // ── Step 5: Smart Campaign 예약 ───────────────────────────────────────
    const runAt = `${startDate}T${startTime}`;

    // 에셋의 token 필드 → SC 예약 시 inline 주입 ...
    const scTokens: { name: string; value: string }[] = [];
    if (asset) {
      if (asset.marketo_token_reward_url && campaign.reward_url)
        scTokens.push({ name: asset.marketo_token_reward_url, value: campaign.reward_url });
      // ... (나머지 토큰)
    }

    log(appDb, id, 'schedule_campaign', 'running', ...);
    await scheduleCampaign(programId, runAt, scTokens.length > 0 ? scTokens : undefined);
    log(appDb, id, 'schedule_campaign', 'done', ...);

    appDb.prepare(`UPDATE campaigns SET marketo_campaign_id=?, status='scheduled', updated_at=? WHERE id=?`)
      .run(String(programId), new Date().toISOString(), id);
    ...
```

전체를 아래로 교체:

```typescript
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
```

- [ ] **Step 5: catch 블록 에러 메시지 수정**

현재 catch 블록의 `failMsg`를:

```typescript
    const failMsg = `Marketo 예약 실패: ${msg}. Marketo UI에서 Smart Campaign(ID: ${programId})를 직접 예약해주세요.`;
```

아래로 교체:

```typescript
    const failMsg = `Marketo Email Program 예약 실패: ${msg}. Marketo UI에서 Email Program(ID: ${epId})을 직접 승인/예약해주세요.`;
```

- [ ] **Step 6: TypeScript 확인**

```bash
npx tsc --noEmit 2>&1 | head -30
```

- [ ] **Step 7: 커밋**

```bash
git add app/api/campaigns/[id]/approve/route.ts
git commit -m "feat: switch Phase 2 to Email Program API with RTZ scheduling"
```

---

## Task 7: Cancel Route — EP Unapprove API 취소

**Files:**
- Modify: `app/api/campaigns/[id]/cancel/route.ts`

- [ ] **Step 1: import에 Marketo 함수 + Segment 타입 추가**

기존 import:

```typescript
import { Campaign } from '@/lib/types';
```

아래로 교체:

```typescript
import { Campaign, Segment } from '@/lib/types';
import { unapproveEmailProgram } from '@/lib/marketo';
```

- [ ] **Step 2: Step 2 전체(SC 취소 불가 안내 블록) 교체**

현재 Step 2 블록(`// ── Step 2: Smart Campaign 취소 불가 안내` 주석부터 `return Response.json(...)` 까지) 전체를 아래로 교체:

```typescript
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
    // EP ID를 알 수 없는 경우 — 수동 취소 안내 (기존 동작 유지)
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

    // unapprove 성공 → draft로 복귀
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

    // 실패해도 'cancelling' 유지 — Phase 1 재실행 차단
    appDb.prepare(`UPDATE campaigns SET error_message=?, updated_at=? WHERE id=?`)
      .run(failMsg, now, id);
    appDb.prepare(`INSERT INTO job_logs (id, campaign_id, step, status, message, created_at) VALUES (?,?,?,?,?,?)`)
      .run(uuid(), id, 'cancel', 'error', failMsg, logNow);

    return Response.json({ success: true, data: { status: 'cancelling', message: failMsg } });
  }
```

- [ ] **Step 3: 파일 최상단 주석 업데이트**

파일 최상단 주석 블록 전체를 아래로 교체:

```typescript
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
```

- [ ] **Step 4: TypeScript 확인**

```bash
npx tsc --noEmit 2>&1 | head -30
```

- [ ] **Step 5: 커밋**

```bash
git add app/api/campaigns/[id]/cancel/route.ts
git commit -m "feat: cancel route uses Email Program unapprove API instead of manual SC cancellation"
```

---

## 최종 검증 절차

- [ ] **1. 앱 기동**

```bash
npm run dev
```

- [ ] **2. 세그먼트 편집** — `/segments` → 기존 세그먼트 선택 → "Email Program ID" 필드에 실제 EP ID 입력 후 저장

- [ ] **3. 캠페인 생성 + 즉시 실행** — 해당 세그먼트를 사용하는 캠페인 생성 → 즉시 실행 → 실행 로그에서 확인:
  - `set_ep_tokens: done` — My Token 주입 성공
  - 테스트 메일 수신 → 이미지, 제목, 보상 URL이 실제 값으로 치환됨

- [ ] **4. 발송 승인** — `awaiting_approval` 상태에서 승인 버튼 → 실행 로그 확인:
  - `ep_unapprove: done`
  - `set_ep_tokens: done`
  - `ep_schedule: done` (RTZ 활성화)
  - `ep_approve: done`
  - Marketo Marketing Activities에서 해당 Email Program → Schedule 탭 → "Recipient Time Zone: Enabled" 확인

- [ ] **5. 예약 취소** — `scheduled` 상태에서 취소 버튼 → 로그에 `cancel: done` + 상태가 `draft`로 복귀 확인

---

## 자기 검토 결과

**스펙 커버리지:** RTZ(Task 6 ✅), 토큰 치환(Task 5 ✅), cancel(Task 7 ✅), DB/타입(Task 1 ✅), API/UI(Task 2~4 ✅)

**플레이스홀더 없음:** 모든 단계에 실제 코드 포함 확인

**타입 일관성:** `buildEpTokenPayload`(Task 2 정의) → `run/route.ts`(Task 5), `approve/route.ts`(Task 6) 모두 동일 시그니처 사용 확인
