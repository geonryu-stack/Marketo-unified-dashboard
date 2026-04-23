# 새 발송 대시보드 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 기존 캠페인 위저드와 별개로, `/send` 페이지에 그룹 중심 주간 발송 설정 UI를 추가한다 — 좌측 그룹 패널에서 그룹을 선택하면 우측에 해당 주 월~일 7개 행이 표시되고, 각 날짜마다 Marketo 에셋·발송 시각·RTZ/KST를 개별 설정한 뒤 벌크 테스트 발송과 일괄 예약을 수행한다.

**Architecture:** SQLite에 `groups`(발송 그룹)와 `send_schedules`(날짜별 발송 설정) 테이블을 추가한다. Next.js App Router Server Component가 초기 그룹 목록을 서버에서 조회해 Client Component에 전달하며, 이후 스케줄 조회·저장·테스트·예약은 REST API를 통해 클라이언트에서 처리한다. Marketo 에셋 목록은 `/api/marketo/emails` 엔드포인트가 Marketo REST API에서 실시간으로 가져온다.

**Tech Stack:** Next.js 16 App Router, better-sqlite3, Marketo REST API, Tailwind CSS, lucide-react

---

## File Map

| 파일 | 역할 | 작업 |
|------|------|------|
| `db/sqlite.ts` | groups + send_schedules 테이블 마이그레이션 + 기본 그룹 시딩 | 수정 |
| `lib/types.ts` | `SendGroup`, `DaySend`, `MarketoEmailItem` 타입 추가 | 수정 |
| `lib/utils.ts` | `getWeekStart()`, `getWeekDates()` 추가 | 수정 |
| `lib/marketo.ts` | `getMarketoEmails()` 추가 | 수정 |
| `components/layout/sidebar.tsx` | "새 발송" 네비 항목 추가 | 수정 |
| `app/api/groups/route.ts` | GET 그룹 목록 | 신규 |
| `app/api/marketo/emails/route.ts` | GET Marketo 이메일 에셋 목록 | 신규 |
| `app/api/send-schedules/route.ts` | GET(주간 조회) + PUT(upsert) + DELETE(행 삭제) | 신규 |
| `app/api/send-schedules/test/route.ts` | POST 벌크 테스트 발송 | 신규 |
| `app/api/send-schedules/schedule/route.ts` | POST 일괄 예약 | 신규 |
| `components/send/day-row.tsx` | 개별 요일 행 (토글·에셋·시각·RTZ/KST·상태뱃지) | 신규 |
| `components/send/week-schedule.tsx` | 주간 그리드 + 주 네비게이션 + 하단 액션 바 | 신규 |
| `components/send/group-panel.tsx` | 좌측 그룹 목록 패널 | 신규 |
| `components/send/send-page-client.tsx` | 클라이언트 상태 오케스트레이터 | 신규 |
| `app/send/page.tsx` | Server Component — 그룹 목록 초기 로드 | 신규 |

---

## Task 1: DB 테이블 추가 및 타입 정의

**Files:**
- Modify: `db/sqlite.ts`
- Modify: `lib/types.ts`

- [ ] **Step 1: `db/sqlite.ts`에 groups + send_schedules 테이블 추가**

  `initSchema` 함수의 `db.exec(...)` 블록 끝(job_logs 테이블 바로 다음)에 두 테이블을 추가한다.

  ```typescript
  // db/sqlite.ts — initSchema 내 db.exec 블록에 추가
  CREATE TABLE IF NOT EXISTS groups (
    id                   TEXT PRIMARY KEY,
    name                 TEXT NOT NULL,
    marketo_campaign_id  INTEGER NOT NULL,
    marketo_list_id      INTEGER NOT NULL,
    sort_order           INTEGER NOT NULL DEFAULT 0
  );

  CREATE TABLE IF NOT EXISTS send_schedules (
    id                  TEXT PRIMARY KEY,
    group_id            TEXT NOT NULL,
    send_date           TEXT NOT NULL,
    marketo_email_id    INTEGER NOT NULL,
    marketo_email_name  TEXT NOT NULL DEFAULT '',
    send_time           TEXT NOT NULL DEFAULT '10:00',
    timezone            TEXT NOT NULL DEFAULT 'RTZ',
    status              TEXT NOT NULL DEFAULT 'draft',
    test_sent_at        TEXT,
    scheduled_at        TEXT,
    error_message       TEXT,
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL,
    UNIQUE(group_id, send_date)
  );
  ```

- [ ] **Step 2: `db/sqlite.ts`에 기본 그룹 시딩 추가**

  `initSchema` 함수 끝(migrations 루프 다음)에 추가한다.

  ```typescript
  // 기본 발송 그룹 시딩 — groups 테이블이 비어있을 때만 삽입
  const groupCount = (db.prepare('SELECT COUNT(*) AS c FROM groups').get() as { c: number }).c;
  if (groupCount === 0) {
    const seedGroups = [
      { id: 'active-a', name: 'Active A',  marketo_campaign_id: 7610, marketo_list_id: 8293, sort_order: 0 },
      { id: 'active-b', name: 'Active B',  marketo_campaign_id: 7611, marketo_list_id: 8294, sort_order: 1 },
      { id: 'fp-active', name: 'FP Active', marketo_campaign_id: 7613, marketo_list_id: 8296, sort_order: 2 },
      { id: 'np-active', name: 'NP Active', marketo_campaign_id: 7612, marketo_list_id: 8295, sort_order: 3 },
    ];
    const insert = db.prepare(
      `INSERT INTO groups (id, name, marketo_campaign_id, marketo_list_id, sort_order)
       VALUES (@id, @name, @marketo_campaign_id, @marketo_list_id, @sort_order)`
    );
    for (const g of seedGroups) insert.run(g);
  }
  ```

- [ ] **Step 3: `lib/types.ts`에 타입 추가**

  파일 끝에 추가한다.

  ```typescript
  // --- 새 발송 대시보드 ---

  export interface SendGroup {
    id: string;
    name: string;
    marketo_campaign_id: number;
    marketo_list_id: number;
    sort_order: number;
  }

  export interface DaySend {
    id: string;
    group_id: string;
    send_date: string;           // 'YYYY-MM-DD'
    marketo_email_id: number;
    marketo_email_name: string;
    send_time: string;           // 'HH:MM'
    timezone: 'RTZ' | 'KST';
    status: 'draft' | 'test_sent' | 'scheduled' | 'sent' | 'failed';
    test_sent_at: string | null;
    scheduled_at: string | null;
    error_message: string | null;
    created_at: string;
    updated_at: string;
  }

  export interface MarketoEmailItem {
    id: number;
    name: string;
    status: string;
    updatedAt: string;
  }
  ```

- [ ] **Step 4: 빌드로 타입 검증**

  ```bash
  npm run build 2>&1 | tail -20
  ```

  Expected: 빌드 성공 (에러 없음)

- [ ] **Step 5: 커밋**

  ```bash
  git add db/sqlite.ts lib/types.ts
  git commit -m "feat: add groups + send_schedules tables and types"
  ```

---

## Task 2: 유틸 함수 + Marketo 이메일 목록 API 추가

**Files:**
- Modify: `lib/utils.ts`
- Modify: `lib/marketo.ts`

- [ ] **Step 1: `lib/utils.ts`에 주간 유틸 함수 추가**

  파일 끝에 추가한다.

  ```typescript
  /** 주어진 날짜가 속한 주의 월요일 날짜를 'YYYY-MM-DD'로 반환 */
  export function getWeekStart(date: Date = new Date()): string {
    const d = new Date(date);
    const day = d.getDay(); // 0=일, 1=월, ...6=토
    const diff = day === 0 ? -6 : 1 - day; // 월요일로 조정
    d.setDate(d.getDate() + diff);
    return d.toISOString().slice(0, 10);
  }

  /** weekStart(YYYY-MM-DD, 월요일)로부터 7일(월~일) 날짜 배열 반환 */
  export function getWeekDates(weekStart: string): string[] {
    const dates: string[] = [];
    const start = new Date(weekStart + 'T00:00:00');
    for (let i = 0; i < 7; i++) {
      const d = new Date(start);
      d.setDate(start.getDate() + i);
      dates.push(d.toISOString().slice(0, 10));
    }
    return dates;
  }

  /** 'YYYY-MM-DD' 날짜를 한국어 요일명으로 변환 */
  export function getDayLabel(date: string): string {
    const labels = ['일', '월', '화', '수', '목', '금', '토'];
    return labels[new Date(date + 'T00:00:00').getDay()];
  }

  /** 날짜가 토요일(6) 또는 일요일(0)인지 여부 */
  export function isWeekend(date: string): boolean {
    const day = new Date(date + 'T00:00:00').getDay();
    return day === 0 || day === 6;
  }
  ```

- [ ] **Step 2: `lib/marketo.ts`에 `getMarketoEmails` 추가**

  파일 끝(또는 `sendSampleEmail` 다음)에 추가한다.

  ```typescript
  /** Marketo 이메일 에셋 목록 조회 (approved 상태만, 최대 200개) */
  export async function getMarketoEmails(): Promise<MarketoEmailItem[]> {
    const data = await mkRequest<{ result: MarketoEmailItem[] }>(
      'GET',
      '/rest/asset/v1/emails.json?status=approved&maxReturn=200&orderBy=updatedAt&sortOrder=DESC'
    );
    return data.result ?? [];
  }
  ```

  `MarketoEmailItem`을 `lib/types.ts`에서 import해야 하므로, `lib/marketo.ts` 상단의 import 블록에 추가한다:

  ```typescript
  import { MarketoEmailItem } from '@/lib/types';
  ```

- [ ] **Step 3: 빌드 검증**

  ```bash
  npm run build 2>&1 | tail -20
  ```

  Expected: 빌드 성공

- [ ] **Step 4: 커밋**

  ```bash
  git add lib/utils.ts lib/marketo.ts
  git commit -m "feat: add week utils and getMarketoEmails to marketo lib"
  ```

---

## Task 3: API 라우트 — groups + marketo/emails

**Files:**
- Create: `app/api/groups/route.ts`
- Create: `app/api/marketo/emails/route.ts`

- [ ] **Step 1: `app/api/groups/route.ts` 생성**

  ```typescript
  import { getDb } from '@/db/sqlite';
  import { SendGroup } from '@/lib/types';

  export const dynamic = 'force-dynamic';

  export async function GET() {
    const db = getDb();
    const groups = db
      .prepare('SELECT * FROM groups ORDER BY sort_order ASC')
      .all() as SendGroup[];
    return Response.json({ success: true, data: groups });
  }
  ```

- [ ] **Step 2: `app/api/marketo/emails/route.ts` 생성**

  ```typescript
  import { getMarketoEmails } from '@/lib/marketo';

  export const dynamic = 'force-dynamic';

  export async function GET() {
    try {
      const emails = await getMarketoEmails();
      return Response.json({ success: true, data: emails });
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err);
      return Response.json({ success: false, error: msg }, { status: 500 });
    }
  }
  ```

- [ ] **Step 3: 개발 서버에서 엔드포인트 확인**

  ```bash
  curl -s http://localhost:3001/api/groups | python3 -m json.tool | head -20
  curl -s http://localhost:3001/api/marketo/emails | python3 -m json.tool | head -20
  ```

  Expected:
  - `/api/groups` → `{"success":true,"data":[{"id":"active-a","name":"Active A",...},...]}`
  - `/api/marketo/emails` → `{"success":true,"data":[...]}` (Marketo 에셋 목록)

- [ ] **Step 4: 커밋**

  ```bash
  git add app/api/groups/route.ts app/api/marketo/emails/route.ts
  git commit -m "feat: add /api/groups and /api/marketo/emails routes"
  ```

---

## Task 4: API 라우트 — send-schedules CRUD

**Files:**
- Create: `app/api/send-schedules/route.ts`

- [ ] **Step 1: `app/api/send-schedules/route.ts` 생성**

  ```typescript
  import { NextRequest } from 'next/server';
  import { getDb } from '@/db/sqlite';
  import { DaySend } from '@/lib/types';
  import { v4 as uuid } from 'uuid';

  export const dynamic = 'force-dynamic';

  /** GET /api/send-schedules?groupId=active-a&weekStart=2025-04-28 */
  export async function GET(req: NextRequest) {
    const { searchParams } = req.nextUrl;
    const groupId = searchParams.get('groupId');
    const weekStart = searchParams.get('weekStart'); // YYYY-MM-DD (월요일)

    if (!groupId || !weekStart) {
      return Response.json({ success: false, error: 'groupId, weekStart 파라미터 필요' }, { status: 400 });
    }

    // weekStart(월)부터 +6일(일)까지
    const weekEnd = new Date(weekStart + 'T00:00:00');
    weekEnd.setDate(weekEnd.getDate() + 6);
    const weekEndStr = weekEnd.toISOString().slice(0, 10);

    const db = getDb();
    const schedules = db
      .prepare(
        `SELECT * FROM send_schedules
         WHERE group_id = ? AND send_date BETWEEN ? AND ?
         ORDER BY send_date ASC`
      )
      .all(groupId, weekStart, weekEndStr) as DaySend[];

    return Response.json({ success: true, data: schedules });
  }

  /** PUT /api/send-schedules — upsert (토글 ON 시 저장) */
  export async function PUT(req: NextRequest) {
    const body = await req.json() as {
      groupId: string;
      date: string;
      marketoEmailId: number;
      marketoEmailName: string;
      sendTime: string;
      timezone: 'RTZ' | 'KST';
    };

    const { groupId, date, marketoEmailId, marketoEmailName, sendTime, timezone } = body;
    if (!groupId || !date || !marketoEmailId || !sendTime) {
      return Response.json({ success: false, error: 'groupId, date, marketoEmailId, sendTime 필수' }, { status: 400 });
    }

    const db = getDb();
    const now = new Date().toISOString();

    // 이미 scheduled/sent 상태인 경우 수정 불가
    const existing = db
      .prepare('SELECT status FROM send_schedules WHERE group_id = ? AND send_date = ?')
      .get(groupId, date) as { status: string } | undefined;

    if (existing && (existing.status === 'scheduled' || existing.status === 'sent')) {
      return Response.json(
        { success: false, error: `이미 ${existing.status} 상태입니다. 수정하려면 예약을 취소하세요.` },
        { status: 409 }
      );
    }

    db.prepare(`
      INSERT INTO send_schedules
        (id, group_id, send_date, marketo_email_id, marketo_email_name, send_time, timezone, status, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)
      ON CONFLICT(group_id, send_date) DO UPDATE SET
        marketo_email_id   = excluded.marketo_email_id,
        marketo_email_name = excluded.marketo_email_name,
        send_time          = excluded.send_time,
        timezone           = excluded.timezone,
        status             = CASE WHEN status IN ('scheduled','sent') THEN status ELSE 'draft' END,
        updated_at         = excluded.updated_at
    `).run(uuid(), groupId, date, marketoEmailId, marketoEmailName, sendTime, timezone, now, now);

    const saved = db
      .prepare('SELECT * FROM send_schedules WHERE group_id = ? AND send_date = ?')
      .get(groupId, date) as DaySend;

    return Response.json({ success: true, data: saved });
  }

  /** DELETE /api/send-schedules?groupId=active-a&date=2025-04-29 — 토글 OFF 시 삭제 */
  export async function DELETE(req: NextRequest) {
    const { searchParams } = req.nextUrl;
    const groupId = searchParams.get('groupId');
    const date = searchParams.get('date');

    if (!groupId || !date) {
      return Response.json({ success: false, error: 'groupId, date 파라미터 필요' }, { status: 400 });
    }

    const db = getDb();
    const existing = db
      .prepare('SELECT status FROM send_schedules WHERE group_id = ? AND send_date = ?')
      .get(groupId, date) as { status: string } | undefined;

    if (existing?.status === 'scheduled' || existing?.status === 'sent') {
      return Response.json(
        { success: false, error: `이미 ${existing.status} 상태입니다. 예약을 먼저 취소하세요.` },
        { status: 409 }
      );
    }

    db.prepare('DELETE FROM send_schedules WHERE group_id = ? AND send_date = ?').run(groupId, date);
    return Response.json({ success: true });
  }
  ```

- [ ] **Step 2: 빌드 검증**

  ```bash
  npm run build 2>&1 | tail -20
  ```

- [ ] **Step 3: 커밋**

  ```bash
  git add app/api/send-schedules/route.ts
  git commit -m "feat: add /api/send-schedules GET/PUT/DELETE"
  ```

---

## Task 5: API 라우트 — 벌크 테스트 발송

**Files:**
- Create: `app/api/send-schedules/test/route.ts`

- [ ] **Step 1: `app/api/send-schedules/test/route.ts` 생성**

  ```typescript
  import { NextRequest } from 'next/server';
  import { getDb } from '@/db/sqlite';
  import { sendSampleEmail } from '@/lib/marketo';
  import { DaySend } from '@/lib/types';

  export const dynamic = 'force-dynamic';

  const TEST_EMAILS = (process.env.SEND_TEST_EMAIL_TO || '')
    .split(',').map((e) => e.trim()).filter(Boolean);

  /**
   * POST /api/send-schedules/test
   * Body: { groupId: string, weekStart: string }
   *
   * 해당 그룹·주의 draft 상태 스케줄에 순서대로 테스트 메일 발송.
   * 각 날짜별 sendSampleEmail 호출 후 status → 'test_sent'.
   */
  export async function POST(req: NextRequest) {
    const body = await req.json() as { groupId: string; weekStart: string };
    const { groupId, weekStart } = body;

    if (!groupId || !weekStart) {
      return Response.json({ success: false, error: 'groupId, weekStart 필수' }, { status: 400 });
    }
    if (TEST_EMAILS.length === 0) {
      return Response.json(
        { success: false, error: 'SEND_TEST_EMAIL_TO 환경변수가 설정되지 않았습니다.' },
        { status: 500 }
      );
    }

    const weekEnd = new Date(weekStart + 'T00:00:00');
    weekEnd.setDate(weekEnd.getDate() + 6);
    const weekEndStr = weekEnd.toISOString().slice(0, 10);

    const db = getDb();
    const targets = db
      .prepare(
        `SELECT * FROM send_schedules
         WHERE group_id = ? AND send_date BETWEEN ? AND ?
           AND status IN ('draft', 'test_sent')
         ORDER BY send_date ASC`
      )
      .all(groupId, weekStart, weekEndStr) as DaySend[];

    if (targets.length === 0) {
      return Response.json({ success: false, error: '테스트할 활성 스케줄이 없습니다.' }, { status: 400 });
    }

    const results: { date: string; success: boolean; error?: string }[] = [];

    for (const schedule of targets) {
      try {
        for (const addr of TEST_EMAILS) {
          await sendSampleEmail(schedule.marketo_email_id, addr);
        }
        db.prepare(
          `UPDATE send_schedules SET status='test_sent', test_sent_at=?, updated_at=? WHERE id=?`
        ).run(new Date().toISOString(), new Date().toISOString(), schedule.id);
        results.push({ date: schedule.send_date, success: true });
      } catch (err) {
        const msg = err instanceof Error ? err.message : String(err);
        db.prepare(
          `UPDATE send_schedules SET error_message=?, updated_at=? WHERE id=?`
        ).run(msg, new Date().toISOString(), schedule.id);
        results.push({ date: schedule.send_date, success: false, error: msg });
      }
    }

    const allOk = results.every((r) => r.success);
    return Response.json({ success: allOk, data: results }, { status: allOk ? 200 : 207 });
  }
  ```

- [ ] **Step 2: 빌드 검증**

  ```bash
  npm run build 2>&1 | tail -20
  ```

- [ ] **Step 3: 커밋**

  ```bash
  git add app/api/send-schedules/test/route.ts
  git commit -m "feat: add /api/send-schedules/test bulk test route"
  ```

---

## Task 6: API 라우트 — 일괄 예약

**Files:**
- Create: `app/api/send-schedules/schedule/route.ts`

- [ ] **Step 1: `app/api/send-schedules/schedule/route.ts` 생성**

  ```typescript
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
  ```

- [ ] **Step 2: 빌드 검증**

  ```bash
  npm run build 2>&1 | tail -20
  ```

- [ ] **Step 3: 커밋**

  ```bash
  git add app/api/send-schedules/schedule/route.ts
  git commit -m "feat: add /api/send-schedules/schedule bulk schedule route"
  ```

---

## Task 7: DayRow 컴포넌트

**Files:**
- Create: `components/send/day-row.tsx`

- [ ] **Step 1: `components/send/day-row.tsx` 생성**

  ```tsx
  'use client';

  import { DaySend, MarketoEmailItem } from '@/lib/types';
  import { getDayLabel, isWeekend } from '@/lib/utils';

  interface DayRowProps {
    date: string;                                // 'YYYY-MM-DD'
    emails: MarketoEmailItem[];
    schedule: DaySend | null;
    isPast: boolean;
    onToggleOn: (date: string) => void;
    onToggleOff: (date: string) => void;
    onFieldBlur: (date: string, field: keyof Pick<DaySend, 'marketo_email_id' | 'marketo_email_name' | 'send_time' | 'timezone'>, value: string | number) => void;
    onSave: (date: string, patch: Partial<DaySend>) => void;
  }

  export function DayRow({ date, emails, schedule, isPast, onToggleOn, onToggleOff, onSave }: DayRowProps) {
    const isOn = schedule !== null;
    const dayLabel = getDayLabel(date);
    const weekend = isWeekend(date);
    const isLocked = schedule?.status === 'scheduled' || schedule?.status === 'sent';

    const statusChip = (() => {
      if (!schedule) return null;
      if (schedule.status === 'scheduled') return <span className="chip chip-blue">📅 예약됨</span>;
      if (schedule.status === 'test_sent') return <span className="chip chip-green">✅ 테스트 완료</span>;
      if (schedule.status === 'failed')    return <span className="chip chip-red">❌ 오류</span>;
      return <span className="chip chip-yellow">⏳ 대기</span>;
    })();

    function handleToggle() {
      if (isPast || isLocked) return;
      if (isOn) {
        onToggleOff(date);
      } else {
        onToggleOn(date);
      }
    }

    function handleEmailChange(e: React.ChangeEvent<HTMLSelectElement>) {
      const emailId = parseInt(e.target.value, 10);
      const emailName = emails.find((em) => em.id === emailId)?.name ?? '';
      onSave(date, { marketo_email_id: emailId, marketo_email_name: emailName });
    }

    function handleTimeBlur(e: React.FocusEvent<HTMLInputElement>) {
      onSave(date, { send_time: e.target.value });
    }

    function handleTzClick(tz: 'RTZ' | 'KST') {
      onSave(date, { timezone: tz });
    }

    return (
      <div className={[
        'flex items-center gap-3 rounded-xl border px-4 py-3 transition-all',
        isOn && !isLocked ? 'border-indigo-200 bg-indigo-50/40' : 'border-slate-200 bg-white',
        !isOn ? 'opacity-50' : '',
      ].join(' ')}>

        {/* Toggle */}
        <label className="relative inline-flex items-center cursor-pointer flex-shrink-0">
          <input
            type="checkbox"
            className="sr-only peer"
            checked={isOn}
            disabled={isPast || isLocked}
            onChange={handleToggle}
          />
          <div className="w-10 h-6 bg-slate-200 peer-checked:bg-indigo-500 rounded-full
                          after:content-[''] after:absolute after:top-[3px] after:left-[3px]
                          after:bg-white after:rounded-full after:h-[18px] after:w-[18px]
                          after:transition-all peer-checked:after:translate-x-4
                          peer-disabled:opacity-40" />
        </label>

        {/* Day label */}
        <div className="w-12 flex-shrink-0">
          <div className={`text-sm font-bold ${weekend ? 'text-red-500' : 'text-slate-800'}`}>
            {dayLabel}
          </div>
          <div className="text-xs text-slate-400">{date.slice(5)}</div>
        </div>

        {/* Fields — disabled when OFF or locked */}
        <div className={`flex gap-2 flex-1 items-end ${!isOn || isLocked ? 'pointer-events-none opacity-40' : ''}`}>

          {/* 에셋 선택 */}
          <div className="flex flex-col flex-[2] min-w-0">
            <label className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide mb-1">에셋</label>
            <select
              className="h-8 px-2 text-sm border border-slate-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300 truncate"
              value={schedule?.marketo_email_id ?? ''}
              onChange={handleEmailChange}
              disabled={!isOn || isLocked}
            >
              <option value="">에셋 선택...</option>
              {emails.map((em) => (
                <option key={em.id} value={em.id}>{em.name}</option>
              ))}
            </select>
          </div>

          {/* 발송 시각 */}
          <div className="flex flex-col w-24">
            <label className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide mb-1">발송 시각</label>
            <input
              type="time"
              className="h-8 px-2 text-sm border border-slate-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-300"
              defaultValue={schedule?.send_time ?? '10:00'}
              key={`${date}-time-${schedule?.send_time}`}
              onBlur={handleTimeBlur}
              disabled={!isOn || isLocked}
            />
          </div>

          {/* RTZ / KST 토글 */}
          <div className="flex flex-col w-20">
            <label className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide mb-1">시간대</label>
            <div className="flex h-8 border border-slate-300 rounded-md overflow-hidden">
              {(['RTZ', 'KST'] as const).map((tz) => (
                <button
                  key={tz}
                  type="button"
                  onClick={() => handleTzClick(tz)}
                  disabled={!isOn || isLocked}
                  className={[
                    'flex-1 text-xs font-bold transition-colors',
                    schedule?.timezone === tz
                      ? 'bg-indigo-500 text-white'
                      : 'bg-white text-slate-500 hover:bg-slate-50',
                  ].join(' ')}
                >
                  {tz}
                </button>
              ))}
            </div>
          </div>
        </div>

        {/* Status chip */}
        <div className="w-24 flex justify-end flex-shrink-0">
          {statusChip}
        </div>
      </div>
    );
  }
  ```

  `app/globals.css`(또는 `app/layout.tsx`의 className)에 chip 유틸 클래스를 추가한다:

  ```css
  /* globals.css */
  .chip {
    @apply inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold;
  }
  .chip-green  { @apply bg-green-50 text-green-700 border border-green-200; }
  .chip-blue   { @apply bg-blue-50 text-blue-700 border border-blue-200; }
  .chip-yellow { @apply bg-yellow-50 text-yellow-700 border border-yellow-200; }
  .chip-red    { @apply bg-red-50 text-red-700 border border-red-200; }
  ```

- [ ] **Step 2: 빌드 검증**

  ```bash
  npm run build 2>&1 | tail -20
  ```

- [ ] **Step 3: 커밋**

  ```bash
  git add components/send/day-row.tsx app/globals.css
  git commit -m "feat: add DayRow component"
  ```

---

## Task 8: WeekSchedule 컴포넌트

**Files:**
- Create: `components/send/week-schedule.tsx`

- [ ] **Step 1: `components/send/week-schedule.tsx` 생성**

  ```tsx
  'use client';

  import { useState } from 'react';
  import { DaySend, MarketoEmailItem, SendGroup } from '@/lib/types';
  import { getWeekDates, getWeekStart } from '@/lib/utils';
  import { DayRow } from './day-row';
  import { ChevronLeft, ChevronRight, FlaskConical } from 'lucide-react';

  interface WeekScheduleProps {
    group: SendGroup;
    initialWeekStart: string;
    initialSchedules: DaySend[];
    emails: MarketoEmailItem[];
  }

  export function WeekSchedule({ group, initialWeekStart, initialSchedules, emails }: WeekScheduleProps) {
    const [weekStart, setWeekStart] = useState(initialWeekStart);
    const [schedules, setSchedules] = useState<DaySend[]>(initialSchedules);
    const [loading, setLoading] = useState(false);
    const [testing, setTesting] = useState(false);
    const [scheduling, setScheduling] = useState(false);

    const today = new Date().toISOString().slice(0, 10);
    const currentWeekStart = getWeekStart();
    const weekDates = getWeekDates(weekStart);

    async function loadSchedules(ws: string) {
      setLoading(true);
      try {
        const res = await fetch(`/api/send-schedules?groupId=${group.id}&weekStart=${ws}`);
        const json = await res.json();
        if (json.success) setSchedules(json.data);
      } finally {
        setLoading(false);
      }
    }

    function changeWeek(dir: -1 | 1) {
      const d = new Date(weekStart + 'T00:00:00');
      d.setDate(d.getDate() + dir * 7);
      const newWs = d.toISOString().slice(0, 10);
      setWeekStart(newWs);
      loadSchedules(newWs);
    }

    function getScheduleForDate(date: string): DaySend | null {
      return schedules.find((s) => s.send_date === date) ?? null;
    }

    async function handleToggleOn(date: string) {
      // 기본값으로 행 생성
      const res = await fetch('/api/send-schedules', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          groupId: group.id,
          date,
          marketoEmailId: emails[0]?.id ?? 0,
          marketoEmailName: emails[0]?.name ?? '',
          sendTime: '10:00',
          timezone: 'RTZ',
        }),
      });
      const json = await res.json();
      if (json.success) {
        setSchedules((prev) => {
          const filtered = prev.filter((s) => s.send_date !== date);
          return [...filtered, json.data].sort((a, b) => a.send_date.localeCompare(b.send_date));
        });
      }
    }

    async function handleToggleOff(date: string) {
      await fetch(`/api/send-schedules?groupId=${group.id}&date=${date}`, { method: 'DELETE' });
      setSchedules((prev) => prev.filter((s) => s.send_date !== date));
    }

    async function handleSave(date: string, patch: Partial<DaySend>) {
      const existing = getScheduleForDate(date);
      if (!existing) return;
      const res = await fetch('/api/send-schedules', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          groupId: group.id,
          date,
          marketoEmailId: patch.marketo_email_id ?? existing.marketo_email_id,
          marketoEmailName: patch.marketo_email_name ?? existing.marketo_email_name,
          sendTime: patch.send_time ?? existing.send_time,
          timezone: patch.timezone ?? existing.timezone,
        }),
      });
      const json = await res.json();
      if (json.success) {
        setSchedules((prev) =>
          prev.map((s) => (s.send_date === date ? json.data : s))
        );
      }
    }

    async function handleBulkTest() {
      setTesting(true);
      try {
        const res = await fetch('/api/send-schedules/test', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ groupId: group.id, weekStart }),
        });
        const json = await res.json();
        if (json.success || res.status === 207) await loadSchedules(weekStart);
        if (!json.success) alert(`일부 테스트 발송 실패:\n${JSON.stringify(json.data, null, 2)}`);
      } finally {
        setTesting(false);
      }
    }

    async function handleBulkSchedule() {
      const testDone = schedules.filter((s) => s.send_date >= weekStart && s.status === 'test_sent');
      if (testDone.length === 0) {
        alert('테스트 발송을 먼저 완료해 주세요.');
        return;
      }
      setScheduling(true);
      try {
        const res = await fetch('/api/send-schedules/schedule', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ groupId: group.id, weekStart }),
        });
        const json = await res.json();
        if (json.success || res.status === 207) await loadSchedules(weekStart);
        if (!json.success) alert(`일부 예약 실패:\n${JSON.stringify(json.data, null, 2)}`);
      } finally {
        setScheduling(false);
      }
    }

    const activeSchedules = schedules.filter((s) => weekDates.includes(s.send_date));
    const testDoneCount = activeSchedules.filter((s) => s.status === 'test_sent' || s.status === 'scheduled' || s.status === 'sent').length;
    const scheduledCount = activeSchedules.filter((s) => s.status === 'scheduled' || s.status === 'sent').length;

    // 주간 레이블
    const weekLabel = (() => {
      const start = new Date(weekStart + 'T00:00:00');
      const end = new Date(weekStart + 'T00:00:00');
      end.setDate(end.getDate() + 6);
      return `${start.getMonth() + 1}월 ${start.getDate()}일 ~ ${end.getMonth() + 1}월 ${end.getDate()}일`;
    })();

    return (
      <div className="flex flex-col flex-1 overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-6 pt-5 pb-4">
          <div>
            <h2 className="text-base font-bold text-slate-900">{group.name} — 주간 발송 스케줄</h2>
            <p className="text-xs text-slate-500 mt-0.5">SC #{group.marketo_campaign_id} · 활성 {activeSchedules.length}일</p>
          </div>
          <div className="flex items-center gap-2">
            {/* 주 네비게이션 */}
            <div className="flex items-center gap-1 bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-sm font-medium text-slate-700">
              <button onClick={() => changeWeek(-1)} className="p-0.5 hover:text-indigo-600 transition-colors">
                <ChevronLeft className="h-4 w-4" />
              </button>
              <span className="mx-2">{weekLabel}</span>
              <button onClick={() => changeWeek(1)} className="p-0.5 hover:text-indigo-600 transition-colors">
                <ChevronRight className="h-4 w-4" />
              </button>
            </div>
            <button
              onClick={handleBulkTest}
              disabled={testing || activeSchedules.length === 0}
              className="flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold rounded-lg
                         bg-emerald-50 text-emerald-700 border border-emerald-200
                         hover:bg-emerald-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              <FlaskConical className="h-4 w-4" />
              {testing ? '발송 중...' : '전체 테스트 발송'}
            </button>
          </div>
        </div>

        {/* Day rows */}
        <div className="flex-1 overflow-y-auto px-6 pb-4 space-y-2">
          {loading ? (
            <div className="py-16 text-center text-sm text-slate-400">로딩 중...</div>
          ) : (
            weekDates.map((date) => (
              <DayRow
                key={date}
                date={date}
                emails={emails}
                schedule={getScheduleForDate(date)}
                isPast={weekStart < currentWeekStart || date < today}
                onToggleOn={handleToggleOn}
                onToggleOff={handleToggleOff}
                onFieldBlur={() => {}}
                onSave={handleSave}
              />
            ))
          )}

          {weekStart < currentWeekStart && (
            <p className="text-xs text-slate-400 text-center py-2">과거 주는 조회만 가능합니다.</p>
          )}
        </div>

        {/* Bottom action bar */}
        <div className="border-t border-slate-200 bg-white px-6 py-3 flex items-center justify-between">
          <div className="flex items-center gap-2 text-sm text-slate-600">
            <span className="font-semibold">{group.name}</span>
            <span className="text-slate-400">·</span>
            <span>활성 <strong>{activeSchedules.length}일</strong></span>
            <span className="text-slate-400">·</span>
            <span>테스트 완료 <strong>{testDoneCount}</strong> / {activeSchedules.length}</span>
            {scheduledCount > 0 && (
              <>
                <span className="text-slate-400">·</span>
                <span className="text-blue-600 font-semibold">📅 예약됨 {scheduledCount}일</span>
              </>
            )}
          </div>
          <div className="flex gap-2">
            <button
              onClick={() => { setSchedules([]); loadSchedules(weekStart); }}
              className="px-3 py-1.5 text-sm font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
            >
              새로고침
            </button>
            <button
              onClick={handleBulkSchedule}
              disabled={scheduling || testDoneCount === 0}
              className="px-4 py-1.5 text-sm font-semibold rounded-lg bg-indigo-600 text-white
                         hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              {scheduling ? '예약 중...' : `🚀 ${group.name} 주간 예약하기`}
            </button>
          </div>
        </div>
      </div>
    );
  }
  ```

- [ ] **Step 2: 빌드 검증**

  ```bash
  npm run build 2>&1 | tail -20
  ```

- [ ] **Step 3: 커밋**

  ```bash
  git add components/send/week-schedule.tsx
  git commit -m "feat: add WeekSchedule component"
  ```

---

## Task 9: GroupPanel + SendPageClient + /send 페이지 + 사이드바

**Files:**
- Create: `components/send/group-panel.tsx`
- Create: `components/send/send-page-client.tsx`
- Create: `app/send/page.tsx`
- Modify: `components/layout/sidebar.tsx`

- [ ] **Step 1: `components/send/group-panel.tsx` 생성**

  ```tsx
  'use client';

  import { SendGroup } from '@/lib/types';

  interface GroupPanelProps {
    groups: SendGroup[];
    selectedId: string;
    onSelect: (id: string) => void;
  }

  export function GroupPanel({ groups, selectedId, onSelect }: GroupPanelProps) {
    return (
      <aside className="w-52 flex-shrink-0 bg-white border-r border-slate-200 flex flex-col overflow-y-auto">
        <div className="px-4 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-widest border-b border-slate-100">
          발송 그룹
        </div>
        {groups.map((group) => (
          <button
            key={group.id}
            onClick={() => onSelect(group.id)}
            className={[
              'text-left px-4 py-3 border-l-2 border-b border-slate-50 transition-all',
              selectedId === group.id
                ? 'border-l-indigo-500 bg-indigo-50'
                : 'border-l-transparent hover:bg-slate-50',
            ].join(' ')}
          >
            <div className={`text-sm font-semibold ${selectedId === group.id ? 'text-indigo-700' : 'text-slate-800'}`}>
              {group.name}
            </div>
            <div className="text-xs text-slate-400 mt-0.5">SC #{group.marketo_campaign_id}</div>
          </button>
        ))}
        <div className="flex-1" />
        <div className="mx-3 my-3 px-3 py-2 border border-dashed border-slate-200 rounded-lg text-center text-xs text-slate-400">
          그룹 추가는 DB에<br />직접 insert
        </div>
      </aside>
    );
  }
  ```

- [ ] **Step 2: `components/send/send-page-client.tsx` 생성**

  ```tsx
  'use client';

  import { useState, useEffect, useCallback } from 'react';
  import { SendGroup, DaySend, MarketoEmailItem } from '@/lib/types';
  import { getWeekStart } from '@/lib/utils';
  import { GroupPanel } from './group-panel';
  import { WeekSchedule } from './week-schedule';

  interface SendPageClientProps {
    initialGroups: SendGroup[];
  }

  export function SendPageClient({ initialGroups }: SendPageClientProps) {
    const [selectedGroupId, setSelectedGroupId] = useState(initialGroups[0]?.id ?? '');
    const [weekStart] = useState(getWeekStart());
    const [schedules, setSchedules] = useState<DaySend[]>([]);
    const [emails, setEmails] = useState<MarketoEmailItem[]>([]);
    const [emailsLoading, setEmailsLoading] = useState(true);

    const selectedGroup = initialGroups.find((g) => g.id === selectedGroupId) ?? initialGroups[0];

    // Marketo 이메일 목록 1회 로드
    useEffect(() => {
      fetch('/api/marketo/emails')
        .then((r) => r.json())
        .then((json) => { if (json.success) setEmails(json.data); })
        .finally(() => setEmailsLoading(false));
    }, []);

    // 그룹/주 변경 시 스케줄 로드
    const loadSchedules = useCallback(async (groupId: string, ws: string) => {
      const res = await fetch(`/api/send-schedules?groupId=${groupId}&weekStart=${ws}`);
      const json = await res.json();
      if (json.success) setSchedules(json.data);
    }, []);

    useEffect(() => {
      if (selectedGroupId) loadSchedules(selectedGroupId, weekStart);
    }, [selectedGroupId, weekStart, loadSchedules]);

    if (!selectedGroup) {
      return <div className="flex-1 flex items-center justify-center text-slate-400">그룹이 없습니다.</div>;
    }

    return (
      <div className="flex flex-1 overflow-hidden">
        <GroupPanel
          groups={initialGroups}
          selectedId={selectedGroupId}
          onSelect={(id) => {
            setSelectedGroupId(id);
            setSchedules([]);
          }}
        />
        {emailsLoading ? (
          <div className="flex-1 flex items-center justify-center text-slate-400 text-sm">
            Marketo 에셋 목록 로딩 중...
          </div>
        ) : (
          <WeekSchedule
            key={`${selectedGroupId}-${weekStart}`}
            group={selectedGroup}
            initialWeekStart={weekStart}
            initialSchedules={schedules}
            emails={emails}
          />
        )}
      </div>
    );
  }
  ```

- [ ] **Step 3: `app/send/page.tsx` 생성**

  ```tsx
  import { getDb } from '@/db/sqlite';
  import { SendGroup } from '@/lib/types';
  import { SendPageClient } from '@/components/send/send-page-client';

  export const dynamic = 'force-dynamic';

  export default function SendPage() {
    const db = getDb();
    const groups = db
      .prepare('SELECT * FROM groups ORDER BY sort_order ASC')
      .all() as SendGroup[];

    return (
      <div className="flex flex-col h-full">
        <div className="px-8 py-6 border-b border-slate-200 bg-white">
          <h1 className="text-xl font-bold text-slate-900">새 발송 설정</h1>
          <p className="text-sm text-slate-500 mt-1">
            발송 그룹을 선택하고 이번 주 요일별 에셋·URL·발송 시각을 설정하세요.
          </p>
        </div>
        <SendPageClient initialGroups={groups} />
      </div>
    );
  }
  ```

- [ ] **Step 4: `components/layout/sidebar.tsx`에 "새 발송" 추가**

  `NAV_ITEMS` 배열에 항목을 추가한다. `Send` 아이콘은 이미 import되어 있으므로 `Calendar` 아이콘을 추가로 import한다.

  ```typescript
  // 상단 import 변경: Calendar 추가
  import { LayoutDashboard, Users, Image as ImageIcon, Send, PlusCircle, Zap, Calendar } from 'lucide-react';

  // NAV_ITEMS 배열 변경 — "/campaigns" 앞에 새 항목 삽입
  const NAV_ITEMS = [
    { href: '/', label: '대시보드', icon: LayoutDashboard },
    { href: '/send', label: '새 발송', icon: Calendar },   // ← 추가
    { href: '/segments', label: '세그먼트', icon: Users },
    { href: '/assets', label: '에셋 라이브러리', icon: ImageIcon },
    { href: '/campaigns', label: '캠페인', icon: Send },
  ];
  ```

- [ ] **Step 5: 빌드 검증**

  ```bash
  npm run build 2>&1 | tail -30
  ```

  Expected: 빌드 성공

- [ ] **Step 6: 브라우저에서 `/send` 확인**

  개발 서버(`http://localhost:3001`)에서 사이드바 "새 발송" 클릭 후:
  - 좌측에 4개 그룹 패널 표시
  - 우측에 현재 주 월~일 7개 행 표시
  - 요일 토글 ON → 에셋 드롭다운 활성화 확인
  - 에셋 선택 → 자동 저장 확인 (`/api/send-schedules` PUT 호출)

- [ ] **Step 7: 커밋**

  ```bash
  git add components/send/ app/send/page.tsx components/layout/sidebar.tsx
  git commit -m "feat: add /send page with group panel and weekly schedule UI"
  ```

---

## Task 10: 옵시디언 위키 업데이트

- [ ] **Step 1: 옵시디언 Dev Log 작성**

  `~/Documents/Obsidian_Master_Wiki/80_Dev_Logs/marketo-send-automation/` 에 오늘 날짜로 파일 생성:
  `20260423_HHMM_새발송대시보드_설계및구현.md`

  내용: 설계 배경, 구현된 파일 목록, 향후 참고사항(RTZ 제약, Kickbox 연동 예정)

---

## Self-Review Checklist

### Spec 커버리지

| 스펙 요구사항 | 구현 태스크 |
|-------------|-----------|
| Marketo API 에셋 드롭다운 | Task 2 (`getMarketoEmails`) + Task 3 (`/api/marketo/emails`) |
| 그룹 패널 (확장 가능) | Task 1 (DB groups 테이블) + Task 9 (`GroupPanel`) |
| 월~일 7일 행 | Task 8 (`WeekSchedule` — `getWeekDates` 7개 렌더) |
| 토글 ON/OFF 저장 | Task 4 (PUT/DELETE) + Task 8 (`handleToggleOn/Off`) |
| 날짜별 RTZ/KST 개별 설정 | Task 7 (`DayRow`) + Task 4 (PUT 저장) |
| 벌크 테스트 발송 | Task 5 (`/api/send-schedules/test`) |
| 일괄 예약 | Task 6 (`/api/send-schedules/schedule`) |
| 테스트 전 예약 차단 | Task 6 (`test_sent` 상태 게이트) |
| 예약됨 행 비활성화 | Task 7 (`isLocked` 체크) |
| 주 네비게이션 | Task 8 (`changeWeek` ±7일) |
| 과거 주 수정 불가 | Task 8 (`isPast` 체크) |
| 사이드바 "새 발송" 메뉴 | Task 9 (sidebar 수정) |

### 주요 제약 반영

- **RTZ 제한**: Smart Campaign은 true RTZ 미지원. schedule route에 주석으로 명시. 저장은 하되 예약 시 동일하게 처리.
- **테스트 메일 토큰 미치환**: sendSampleEmail은 tokenList 미지원. Marketo 에셋에 URL이 이미 내장된 것으로 가정.
- **UNIQUE(group_id, send_date)**: PUT에서 ON CONFLICT DO UPDATE, scheduled/sent 상태는 덮어쓰기 차단.
