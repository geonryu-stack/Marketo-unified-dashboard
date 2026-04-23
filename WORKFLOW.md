# Marketo 발송 자동화 — 운영 워크플로우

> 이 문서는 담당자가 실제로 캠페인을 만들고 발송하기까지의 전체 흐름을 설명합니다.

---

## 전체 흐름 한눈에 보기

```
[사전 설정]
 세그먼트 등록 → 에셋 등록
        │
        ▼
[캠페인 생성] → 보상 URL / 예약 일시 / RTZ 시각 입력
        │
        ▼
[자동 실행 — Phase 1]  ← Cron이 scheduled_at 시각에 자동 트리거
  1. 사내 DB 대상자 추출
  2. Marketo 리드 업서트
  3. 고정 Static List 갱신 (기존 멤버 제거 → 새 리드 추가)
  4. Email Program My Token 설정 (이미지/제목/이모지/프리헤더/본문/보상 URL)
  5. 테스트 메일 발송 → SEND_TEST_EMAIL_TO 수신함 확인
        │
        ▼  status: awaiting_approval
[담당자 확인] — 테스트 메일에서 아래 항목 점검
  ✅ 오탈자 없음
  ✅ 이미지 정상 표시
  ✅ 보상 URL 클릭 → 보상 획득 확인
        │
        ├── 문제 있음 → [재검토] 버튼 → draft 복귀 → 수정 후 재실행
        │
        ▼ 문제 없음 → [발송 승인] 버튼
[수동 승인 — Phase 2]
  1. Email Program Unapprove
  2. 스케줄 설정 (날짜 + RTZ 발송 시각)
  3. Email Program Approve
        │
        ▼  status: scheduled
[Marketo RTZ 자동 발송] — 수신자 현지 시간 기준
```

---

## 사전 설정 (최초 1회)

### 1. 세그먼트 등록 (`/segments/new`)

| 필드 | 설명 |
|------|------|
| 세그먼트 이름 | 내부 관리용 이름 (예: 30일 미접속 한국 유저) |
| 필터 조건 | 사내 DB 컬럼 기반 WHERE 조건 빌더 |
| Marketo Email Program ID | Marketing Activities에서 이 세그먼트 전용 Program의 ID |
| Audience Static List ID | Email Program Audience Smart List의 'Member of List'에 연결된 고정 Static List ID |

> **Marketo 확인 방법**
> - Program ID: Marketing Activities → 해당 Program URL의 `#PG{숫자}`
> - Static List ID: Database → 해당 Static List → URL의 `#SL{숫자}`

### 2. 에셋 등록 (`/assets`)

| 필드 | 설명 |
|------|------|
| 에셋 이름 | 내부 식별명 |
| 이미지 URL | 이메일 메인 이미지 |
| 이메일 제목 / 이모지 / 프리헤더 / 본문 텍스트 | 이번 회차 콘텐츠 |
| 발송 방식 | **Token 모드** 권장 (Clone 없이 My Token으로 주입) |
| My Token 이름 | Marketo Program에 등록된 토큰명 (예: `{{my.imageUrl}}`) |

> **Token 모드 Marketo 사전 준비**
> 해당 Program → My Tokens 탭에서 아래 토큰 등록:
> `imageUrl` / `subjectLine` / `emoji` / `preheader` / `bodyText` / `rewardUrl`

### 3. 환경변수 설정 (`.env.local`)

```bash
# 사내 DB
INTERNAL_DB_HOST=...
INTERNAL_DB_PORT=3306
INTERNAL_DB_USER=readonly_user
INTERNAL_DB_PASSWORD=...
INTERNAL_DB_NAME=...

# Marketo
MARKETO_MUNCHKIN_ID=xxx-XXX-xxx
MARKETO_CLIENT_ID=...
MARKETO_CLIENT_SECRET=...

# 발송 자동화
SEND_TEST_EMAIL_TO=your-email@company.com   # 테스트 메일 수신 주소
CRON_SECRET=your-secret-key                 # Cron 엔드포인트 인증
```

---

## 매 회차 발송 절차

### Step 1 — 캠페인 생성 (`/campaigns/new`)

| 필드 | 설명 |
|------|------|
| 캠페인 이름 | 이번 회차 식별명 (예: 4월 4주차 복귀 유저) |
| 세그먼트 선택 | 위에서 등록한 세그먼트 |
| 에셋 선택 | 이번 회차에 사용할 에셋 |
| 보상 URL | 수동 발행 후 붙여넣기 (CONSTRAINT-02) |
| Phase 1 자동 실행 일시 | Cron이 이 시각에 Phase 1을 자동 실행 |
| RTZ 발송 시각 | 수신자 현지 시간 기준 발송 시각 (예: `10:00`) |

### Step 2 — 실행 방식 선택

캠페인 상세 페이지에서 두 가지 중 하나를 선택합니다.

| 버튼 | 동작 |
|------|------|
| **[자동 실행 예약]** | 상태를 `confirmed`로 변경. Cron이 `scheduled_at` 시각에 자동으로 Phase 1을 실행합니다. |
| **[즉시 실행]** | 지금 바로 Phase 1을 실행합니다. 테스트 발송이나 긴급 발송 시 사용합니다. |

### Step 3 — Phase 1 자동 실행 (또는 수동 [실행])

Cron이 `scheduled_at` 시각이 되면 자동으로 Phase 1을 실행합니다.
완료되면 상태가 `awaiting_approval`로 변경되고 테스트 메일이 발송됩니다.

### Step 4 — 테스트 메일 확인 후 승인 또는 재검토

`SEND_TEST_EMAIL_TO` 수신함에서 테스트 메일을 열어 확인합니다.

- **이상 없음** → 캠페인 상세 페이지 → **[발송 승인]** 클릭
- **문제 발견** → **[재검토]** 클릭 → 캠페인이 `draft`로 복귀 → 내용 수정 후 재실행

### Step 5 — Phase 2 자동 처리 (승인 클릭 후)

1. Email Program Unapprove
2. 스케줄 설정 (Phase 1 실행 날짜 + RTZ 발송 시각)
3. Email Program Approve

완료되면 상태가 `scheduled`로 변경됩니다.

### Step 6 — Marketo RTZ 자동 발송

Marketo가 각 수신자의 현지 시간 기준으로 지정 시각에 자동 발송합니다.

---

## 캠페인 상태 흐름

| 상태 | 의미 | 가능한 다음 액션 |
|------|------|----------------|
| `draft` | 초안 | **자동 실행 예약**, **즉시 실행**, 삭제 |
| `confirmed` | Cron 자동 실행 대기 중 | **즉시 실행**, 삭제 |
| `extracting` | DB 추출 중 | — |
| `uploading` | Marketo 리드 업로드 중 | — |
| `preparing` | Token 설정 + 테스트 메일 발송 중 | — |
| `awaiting_approval` | 담당자 테스트 메일 확인 대기 | **발송 승인**, **재검토** |
| `scheduling` | Email Program 예약 설정 중 | — |
| `scheduled` | Marketo 발송 예약 완료 | **예약 취소** |
| `sent` | 발송 완료 | — |
| `failed` | 오류 발생 | **재실행**, 삭제 |

---

## Cron 설정

Phase 1은 `scheduled_at` 시각이 되면 Cron이 자동 실행합니다.

**Vercel Cron** (`vercel.json`):
```json
{
  "crons": [
    {
      "path": "/api/cron/run-due-campaigns",
      "schedule": "* * * * *"
    }
  ]
}
```

**외부 Cron 서비스** (cron-job.org 등):
- URL: `https://your-domain.com/api/cron/run-due-campaigns`
- Method: `GET`
- Header: `Authorization: Bearer {CRON_SECRET}`
- 주기: 1분마다

---

## API 엔드포인트 요약

| 엔드포인트 | 설명 |
|-----------|------|
| `POST /api/campaigns/[id]/run` | Phase 1 실행 (draft / confirmed / failed 상태만) |
| `POST /api/campaigns/[id]/approve` | Phase 2 승인 (awaiting_approval 상태만) |
| `POST /api/campaigns/[id]/reject` | 재검토 요청 → draft 복귀 |
| `POST /api/campaigns/[id]/cancel` | 발송 예약 취소 → Marketo unapprove + draft 복귀 |
| `GET /api/cron/run-due-campaigns` | Cron 자동 실행 엔드포인트 |

---

## 주의사항

- **보상 URL은 자동 생성 불가** (CONSTRAINT-02): 담당자가 직접 발행 후 입력
- **테스트 메일 확인 필수** (CONSTRAINT-08): 승인 없이는 실제 발송 예약 불가
- **예약된 캠페인 재실행 불가**: `scheduled` 상태에서는 `/run` 차단됨. 재실행이 필요하면 [예약 취소] 후 재실행
- **사내 DB는 읽기 전용** (CONSTRAINT-01): SELECT만 허용, 쓰기 쿼리 즉시 오류
