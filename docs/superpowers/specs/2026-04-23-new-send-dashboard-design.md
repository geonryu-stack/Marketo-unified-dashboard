# 새 발송 대시보드 설계 문서

**날짜**: 2026-04-23  
**상태**: 승인됨  
**범위**: 기존 캠페인 위저드를 대체하는 그룹 중심 주간 발송 설정 UI

---

## 1. 배경 및 목적

### 현재 수동 워크플로우
1. 어드민에서 이벤트 코드(보상 URL 포함) 발행
2. Marketo에서 직전 회차 에셋을 Clone → 제목·프리헤더 수정
3. 플랫폼팀이 공유 폴더에 그룹별 대상자 리스트 업로드
4. Kickbox로 신규 이메일 검증 → 통과한 것만 CSV로 저장
5. 각 그룹 Marketo 프로그램에 수동 import
6. RTZ/KST 기준으로 예약 발송 수동 설정

### 자동화 목표 (이번 범위)
- Marketo에서 직접 만들어둔 에셋을 대시보드 드롭다운에서 선택
- 보상 URL 하나만 입력하면 Marketo My Token(`{{my.rewardUrl}}`)으로 자동 주입
- 그룹별 주간 스케줄(월~일 7일)을 한 화면에서 설정
- 벌크 테스트 발송 후 확인 → 전체 예약

---

## 2. 핵심 설계 결정

| 결정 | 선택 | 이유 |
|------|------|------|
| 에셋 관리 위치 | Marketo 직접 관리 | 앱 DB 에셋과 Marketo 에셋의 이중 관리 제거, 리스크 감소 |
| 에셋 목록 표시 | Marketo API 드롭다운 | 앱에서 별도 등록 불필요, Marketo에 추가하면 자동 반영 |
| UI 구조 | 그룹 중심 (그룹 선택 → 요일별 설정) | 에셋 하나가 보통 한 그룹에만 들어가는 실제 패턴에 맞춤 |
| 그룹 확장성 | 좌측 패널 리스트 (탭 아님) | 그룹 수가 늘어도 스크롤로 자연스럽게 대응 |
| 테스트 발송 | 벌크 (활성화된 모든 날짜 한 번에) | 그룹당 1회만 확인하면 되는 실무 패턴 반영 |
| 시간대 설정 | 날짜별 개별 RTZ/KST | 날짜마다 다른 시간대가 필요한 경우 대응 |
| 주간 네비게이션 | ◁ 현재 주 ▷ | 다음 주 스케줄도 미리 설정 가능 |

---

## 3. UI 구조

```
┌─────────────────────────────────────────────────────────────────┐
│ 사이드바: 📊 대시보드 / ✉️ 새 발송 (active) / 📋 발송 이력        │
├──────────────────────────────────────────────────────────────────┤
│ 페이지 헤더: "새 발송 설정"                                        │
├─────────────────┬────────────────────────────────────────────────┤
│  그룹 패널 (좌)  │  주간 스케줄 (우)                               │
│                 │                                                │
│  Active A  ←선택│  Active A — 4월 28일 주     [◁ 이전] [다음 ▷] │
│  Active B       │  [🔬 전체 테스트 발송]                          │
│  FP Active      │                                                │
│  NP Active      │  [토글] 월 4/28 | 에셋▼ | URL_________ | 시각 | RTZ/KST │
│  ...            │  [토글] 화 4/29 | 에셋▼ | URL_________ | 시각 | RTZ/KST │
│  + 추후 그룹     │  ...                                           │
│                 │  [토글] 토 5/3  | 에셋▼ | URL_________ | 시각 | RTZ/KST │
│                 │  [토글] 일 5/4  | 에셋▼ | URL_________ | 시각 | RTZ/KST │
├─────────────────┴────────────────────────────────────────────────┤
│ 하단 바: 활성 2일 요약 · [초기화] [🚀 Active A 주간 예약하기]       │
└──────────────────────────────────────────────────────────────────┘
```

---

## 4. 데이터 모델

### 그룹 (Group) — 앱 DB 또는 환경변수로 관리
```typescript
interface SendGroup {
  id: string;                  // 'active-a'
  name: string;                // 'Active A'
  marketoCampaignId: number;   // 7610
  marketoListId: number;       // 8293
  leadCount?: number;          // 최근 추출 수 (옵션)
}
```

기본 4개 그룹은 DB 또는 환경변수로 관리. 새 그룹 추가 시 DB insert만 하면 좌측 패널에 자동 반영.

### 일별 발송 설정 (DaySend)
```typescript
interface DaySend {
  groupId: string;
  date: string;                // 'YYYY-MM-DD'
  marketoEmailId: number;      // Marketo 에셋 ID (API에서 선택)
  marketoEmailName: string;    // 표시용 이름
  rewardUrl: string;           // 보상 URL
  sendTime: string;            // 'HH:MM'
  timezone: 'RTZ' | 'KST';
  testSentAt?: string;         // 테스트 발송 완료 시각
  scheduledAt?: string;        // 예약 완료 시각
  status: 'draft' | 'test_sent' | 'scheduled' | 'sent' | 'failed';
}
```

### SQLite 테이블: `send_schedules`
```sql
CREATE TABLE send_schedules (
  id              TEXT PRIMARY KEY,
  group_id        TEXT NOT NULL,
  send_date       TEXT NOT NULL,           -- YYYY-MM-DD
  marketo_email_id   INTEGER NOT NULL,
  marketo_email_name TEXT NOT NULL,
  reward_url      TEXT NOT NULL,
  send_time       TEXT NOT NULL,           -- HH:MM
  timezone        TEXT NOT NULL DEFAULT 'RTZ',
  status          TEXT NOT NULL DEFAULT 'draft',
  test_sent_at    TEXT,
  scheduled_at    TEXT,
  error_message   TEXT,
  created_at      TEXT NOT NULL,
  updated_at      TEXT NOT NULL,
  UNIQUE(group_id, send_date)              -- 그룹+날짜 조합은 유일
);
```

---

## 5. API 설계

### Marketo 에셋 목록 조회
```
GET /api/marketo/emails
→ Marketo REST API: GET /asset/v1/emails.json
→ 응답: [{ id, name, status, updatedAt }]
```

### 그룹 목록 조회
```
GET /api/groups
→ SQLite groups 테이블에서 반환
→ 응답: [{ id, name, marketoCampaignId, marketoListId }]
```

### 주간 스케줄 저장 (upsert)
```
PUT /api/send-schedules
Body: { groupId, date, marketoEmailId, marketoEmailName, rewardUrl, sendTime, timezone }
→ UNIQUE(group_id, send_date) 기준 upsert
```

### 벌크 테스트 발송
```
POST /api/send-schedules/test
Body: { groupId, week: 'YYYY-MM-DD' }  // 해당 주의 월요일 날짜
→ 해당 그룹의 해당 주 draft 상태 스케줄들에 순서대로 테스트 메일 발송
→ 각 스케줄의 status → 'test_sent', test_sent_at 업데이트
→ 기존 sendSampleEmail(emailId, toEmail) 재사용
```

**테스트 메일 확인 방법:**
- Marketo에서 에셋 생성 시 보상 URL이 이미 템플릿에 직접 삽입되어 있음
- `sendSampleEmail`로 받은 테스트 메일 내 이미지·버튼을 클릭해 보상 획득까지 확인
- 이상 없으면 예약 버튼 클릭 → 별도 URL 검증 단계 불필요

### 주간 일괄 예약
```
POST /api/send-schedules/schedule
Body: { groupId, week: 'YYYY-MM-DD' }
→ 해당 그룹·주의 test_sent 상태 스케줄들을 Marketo에 예약
→ 각 스케줄 status → 'scheduled'
```

---

## 6. 페이지 & 파일 구조

```
app/
  send/
    page.tsx                  ← 새 발송 메인 페이지 (그룹 패널 + 주간 스케줄)
  api/
    groups/
      route.ts                ← GET: 그룹 목록
    marketo/
      emails/
        route.ts              ← GET: Marketo 이메일 에셋 목록
    send-schedules/
      route.ts                ← GET (주간 조회), PUT (upsert)
      test/
        route.ts              ← POST: 벌크 테스트 발송
      schedule/
        route.ts              ← POST: 일괄 예약

db/
  migrations/
    004_send_schedules.sql    ← send_schedules 테이블 + groups 테이블

components/
  send/
    group-panel.tsx           ← 좌측 그룹 리스트
    week-schedule.tsx         ← 우측 주간 스케줄 (7일 행)
    day-row.tsx               ← 개별 요일 행 컴포넌트
```

---

## 7. 사용자 흐름 (Happy Path)

```
1. /send 접속
   → 좌측 패널: 그룹 목록 로드
   → 첫 번째 그룹 자동 선택
   → 우측: 현재 주(월~일) 7개 행 표시

2. 그룹 선택 (예: Active A)
   → 해당 그룹·현재 주의 기존 draft 스케줄 로드 (있으면 자동 채워짐)

3. 요일 행 토글 ON
   → 에셋 드롭다운 활성화 (Marketo API에서 이메일 목록 로드)
   → URL 입력, 발송 시각, RTZ/KST 설정
   → PUT /api/send-schedules 자동 저장 (blur 이벤트)

4. [🔬 전체 테스트 발송] 클릭
   → 활성화된 날짜에 순서대로 테스트 메일 발송
   → 각 행 상태: ⏳ 대기 → ✅ 완료

5. 테스트 메일 확인 후 이상 없으면
   → [🚀 Active A 주간 예약하기] 클릭
   → test_sent 상태인 스케줄들 Marketo 예약
   → 각 행 상태: 📅 예약됨

6. 다른 그룹 탭 전환 → 반복
```

---

## 8. 엣지 케이스 & 제약

- **UNIQUE(group_id, send_date)**: 같은 그룹·날짜에 중복 예약 불가. upsert로 처리
- **이미 예약된 날짜**: 행에 "예약됨" 뱃지 표시, 토글/필드 비활성화. 취소 버튼 별도 제공
- **Marketo 에셋 목록 로드 실패**: 드롭다운에 "수동 입력" 폴백 (ID 직접 입력)
- **테스트 발송 전 예약 시도**: "테스트 발송을 먼저 완료해 주세요" 경고
- **그룹 추가**: `groups` 테이블에 insert만 하면 UI 자동 반영 — 코드 변경 불필요
- **주간 네비게이션**: 과거 주는 조회만 가능, 새 스케줄 추가 불가 (과거 날짜 토글 비활성)

---

## 9. 기존 코드와의 관계

| 기존 | 변경 |
|------|------|
| `app/campaigns/new` — 캠페인 위저드 | 유지 (기존 캠페인 방식도 병행 지원) |
| `lib/marketo.ts` — scheduleCampaign | 재사용. tokens 파라미터로 rewardUrl 주입 |
| `components/campaign-wizard.tsx` | 건드리지 않음 |
| `app/assets` — 에셋 라이브러리 | 더 이상 새 발송 흐름에서 참조 안 함 (별도 유지) |
| `db/sqlite.ts` | 재사용. send_schedules 테이블 마이그레이션 추가 |

---

## 10. 범위 외 (이번 구현 미포함)

- Kickbox 이메일 검증 (다음 단계)
- 사내 DB 대상자 추출 (기존 캠페인 방식에서 이미 구현됨)
- 발송 이력 페이지 (`/send/history`) — 별도 설계
