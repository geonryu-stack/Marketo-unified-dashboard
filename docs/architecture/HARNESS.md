# HARNESS.md — 파이프라인 하네스 정의

> **하네스**(harness) = 파이프라인을 **안전하게 묶어두는 장치**.
> 가드레일·재시도·관측·격리·롤백·킬스위치를 한 곳에 정의한다.
> 본 문서는 PIPELINE.md의 13스테이지에 직교한다.

## 0. 5대 하네스 축

| 축 | 목적 | 현재 구현 | 보강 권고 |
|----|------|----------|-----------|
| **A. 가드레일** | 잘못된 호출을 *진입 자체* 차단 | INV-01~07, send_time 16h, 4종 체크리스트 | run_id 강제, dry-run 모드 |
| **B. 재시도** | 일시 장애 흡수, 부작용 중복 방지 | `MarketoAPI::curl` 코드별 분기 | metric 카운터 |
| **C. 관측** | 추적·디버깅·이상탐지 | `job_logs` 테이블, cron stdout | structured log + Slack 알림 |
| **D. 격리** | 실패 폭발반경 최소화 | `needs_manual_review`, sibling CAS | feature flag, segment-level pause |
| **E. 킬스위치** | 즉시 중단 | `MARKETO_BULK_ENABLED`, cron 비활성화 | 글로벌 `READ_ONLY_MODE` |

---

## A. 가드레일 (Pre-flight Guards)

### A1. 입력 검증 게이트

| 위치 | 검증 |
|------|------|
| `api/segments.php` POST/PUT | `filters` 빈 배열 차단 (전체 발송 방지) |
| `api/campaigns.php` POST | `send_time >= now + 16h` |
| `api/campaigns.php` save | 동일 + 상태가 `draft\|awaiting_approval\|failed`일 때만 |
| `ScheduleRunner.php` | seg.marketo_audience_list_id / program_id / email_program_id 존재 검사 |
| `MarketoBulkImport::submitBulkImport` | leads 비어있음 + listId ≤ 0 |
| `helpers.php:build_where_clause` | 알 수 없는 필터 필드 (전체 발송 방지) |
| `InternalDB::query` | `assert_readonly` — SELECT/WITH만 허용 |

### A2. 동시성/순서 게이트 (CAS Lock)

| 위치 | 규칙 |
|------|------|
| `api/campaigns.php:approve` | seg 전체 `FOR UPDATE`, sibling 충돌검사, `awaiting_approval`만 진입 가능 |
| `cron/run_due_campaigns.php` | 위와 동일 로직. ORDER BY id로 deadlock 방지 |
| `cron/check_bulk_imports.php` | `bulk_polling → bulk_finalizing` CAS (`WHERE id=? AND status='bulk_polling'`) |
| `api/campaigns.php:reject/resolve-review` | CAS — `WHERE id=? AND status='…'` 1 row만 |

### A3. 인간 게이트

| 위치 | 검증 |
|------|------|
| 결재 카드 | 4종 체크박스 (토큰/시각/대상/렌더) + type-to-confirm |
| `resolve-review` | "Marketo UI 확인 후" 명시 안내, 운영자 노트 누적 |
| Cancel | `marketo_email_program_id` 비어 있으면 자동 취소 차단 (fake cancel 방지) |

### A4. (권고) 신규 가드레일

- **A4-1**: `run_id` UUID를 캠페인 진입시 발급해 모든 로그·외부 호출 헤더에 동반 → cross-cron 추적 가능
- **A4-2**: `DRY_RUN_MODE` config flag → MarketoAPI의 POST/DELETE를 no-op + 가짜 응답
- **A4-3**: send_time **+** scheduled_at 동시 검증 (scheduled_at < now 발견 시 즉시 차단; cron pickup 직전)
- **A4-4**: APP DB 트랜잭션 격리수준 명시 (`READ-COMMITTED` 권장)

---

## B. 재시도 정책

### B1. Marketo HTTP 분류 매트릭스

| 코드 / 사건 | 안전성 | 정책 |
|-------------|--------|------|
| 602 (토큰 만료) | 모두 안전 (처리 전 거절) | 캐시 폐기 + 헤더 재구성 + 즉시 재시도 1회 |
| 606 (rate limit), 615 (concurrency) | 모두 안전 (게이트 거절) | 2s→4s→8s, 최대 3회 |
| 502/503/504 | GET만 안전 | GET 백오프 재시도 / POST·DELETE 즉시 throw |
| 네트워크 오류 | GET만 안전 | 위와 동일 |
| 4xx (404/409/610 등) | 부적격 | 즉시 throw |
| 709 (EP 이미 unapprove) | 정상 케이스 | `unapproveEmailProgramSafe`에서 `already_draft` 반환 |
| 702 (대상 없음) | 토큰 DELETE 시 정상 | 무시 |

### B2. 내부 잡 (Bulk Polling, Activity Polling)

| 잡 | 실패시 동작 |
|----|-----------|
| Bulk status GET | GET이므로 풀 재시도 → 한도 초과 시 RuntimeException (다음 cron이 재폴링) |
| Activity 폴링 | 폴링 자체 실패 시 **DB 상태 미변경** → 다음 cron 재시도 |
| sent 전환 | `WHERE id=? AND status='scheduled'` CAS → 누락 무해 |

### B3. (권고) 보강

- **B3-1**: 재시도 시도횟수·소요시간을 `job_logs.message`에 구조화 (현재는 문자열) — 메트릭화 시 키-값 prefix 권장: `retry=2 elapsed=4.1s`
- **B3-2**: 같은 캠페인의 finalize 중복 호출 차단을 위한 application lock (`SELECT GET_LOCK('camp:UUID', 0)`) — 현재는 sibling CAS로 간접 차단되나 같은 ID 동시 호출은 막히지 않음
- **B3-3**: `TOKEN_CACHE_FILE`은 단일 파일 → 다중 워커 동시 갱신 시 `flock` 사용 권장

---

## C. 관측 (Observability)

### C1. 현 상태

| 신호 | 위치 | 보존기간 | 검색성 |
|------|------|---------|--------|
| 캠페인 상태 머신 | `campaigns.status / poll_status / bulk_status` | 무기한 | UI |
| 단계별 로그 | `job_logs` (step / status / message) | 무기한 | UI(상세) |
| Cron stdout | 콘솔 / Windows Task Scheduler 로그 | OS 정책 | 수동 |
| 에러 메시지 | `campaigns.error_message` (누적) | 무기한 | UI 배너 |

### C2. 표준 로그 키 (현 구현 + 권고 확장)

```text
step: extract | list_refresh | bulk_submit | inject_tokens | schedule_ep | error
status: running | done | error
message: 자유 텍스트 (가능하면 key=value 형식 권장)
```

권고: `run_id`, `actor`(user|cron), `marketo_call_count`, `elapsed_ms` 컬럼/태그 추가.

### C3. (권고) 알림 채널

- **C3-1**: `needs_manual_review` 전환 시 Slack/Teams Webhook
- **C3-2**: `failed` 상태가 60분 내 3건 이상 → 알림
- **C3-3**: Bulk Polling이 `bulk_started_at + 30m` 경과해도 `Importing` → 알림
- **C3-4**: Activity 폴링 `coverage < 0.5 AND elapsed_min > 120` → 의심 알림

### C4. (권고) 대시보드 컬럼

`SELECT status, COUNT(*) FROM campaigns GROUP BY status` 를 30초 주기로 메트릭으로 송출하면 운영 가시성이 크게 좋아짐.

---

## D. 격리 (Blast Radius Control)

### D1. 현 격리 메커니즘

| 격리 단위 | 메커니즘 | 효과 |
|----------|----------|------|
| 단일 캠페인 | try/catch + `set_campaign_status('failed', …)` | 다른 캠페인 무영향 |
| 세그먼트 (=EP) | sibling CAS + `needs_manual_review` | 같은 EP 덮어쓰기 차단 |
| Bulk 잡 | `bulk_polling → bulk_finalizing` CAS | 중복 finalize 차단 |
| 사내 DB | `assert_readonly` | 데이터 무결성 보호 (INV-01) |

### D2. (권고) 추가 격리

- **D2-1**: `segments.is_paused` 컬럼 — UI 토글로 특정 세그먼트의 자동진행만 일시 정지
- **D2-2**: `campaigns.locked_by` 컬럼 — 운영자가 명시적으로 잠금 (cron pickup 차단)
- **D2-3**: `marketo_email_program_id` 별 사용중 잠금 인덱스: `UNIQUE KEY uq_ep_active (marketo_email_program_id, status_in_active_set)` — 현재는 sibling CAS로 간접 보호, 명시 unique constraint 추가 시 race window 완전 봉쇄

---

## E. 킬스위치 (Emergency Stop)

### E1. 현 존재 스위치

| 스위치 | 효과 | 위치 |
|--------|------|------|
| `MARKETO_BULK_ENABLED=false` | 임계값 초과해도 REST 경로 강제 (Bulk API 장애시 회피) | config |
| `INTERNAL_DB_BYPASS_LEADS='...'` | 사내 DB 우회, 고정 주소로 발송 (라이브 테스트용) | config |
| 모든 cron 비활성화 | Windows Task Scheduler에서 disable | OS |

### E2. (권고) 신규 킬스위치

- **E2-1**: `READ_ONLY_MODE` — 모든 POST/PUT/DELETE API와 Marketo 호출을 401/no-op (긴급 동결)
- **E2-2**: `MARKETO_HALT_ALL_SCHEDULE` — finalize 진입 직전 throw로 차단 (운영 중 Marketo 장애시)
- **E2-3**: 캠페인 단위 "긴급 취소" → status='scheduled'에서 EP `unapprove` (구현됨) + `marketo_email_program_id` 없는 경우 안내(구현됨)

---

## F. 단계별 하네스 매트릭스

| 스테이지 | A. 가드 | B. 재시도 | C. 관측 | D. 격리 | E. 킬스위치 |
|----------|--------|-----------|---------|---------|-------------|
| S1 Segment 정의 | filters 비검사 | — | 일반 | DB 트랜잭션 | — |
| S2 Campaign Draft | 16h 검사 | — | INSERT 로그 | — | — |
| S3 Token Lib 주입 | LIB_ID 검사 | 토큰 만료/606/615 재시도 | `inject_tokens` | 실패→failed | — |
| S4 Test Email | 주소 비검사 | POST 5xx 즉시 throw | `send_test_email` | 실패→failed | — |
| S5 Approval | 4 체크박스 + 사람 | — | UI | — | UI 비활성 |
| S6 결재승인 | seg CAS | — | DB transaction | sibling 차단 | — |
| S7a REST | listId 검사 | upsertLeads chunk 재시도 안전 | `list_refresh` | — | — |
| S7b Bulk POST | leads/listId | **POST 5xx 절대 재시도 금지** | `bulk_submit` | bulk_polling 잠금 | `MARKETO_BULK_ENABLED` |
| S8 Bulk 폴링 | CAS bulk_polling→finalizing | GET 풀 재시도 | `bulk_status` | partial fail 차단(INV-05) | cron disable |
| S9 Token 발송주입 | program_id 검사 | 재시도 안전 | `inject_tokens` | EP 미변경=안전구간 | — |
| S10 EP unapprove | — | **POST 5xx 즉시 throw** | `schedule_ep` | 709 안전 | — |
| S11 EP schedule | — | **POST 5xx 즉시 throw** | `schedule_ep` | 실패→needs_manual_review | — |
| S12 sent 전환 | CAS `WHERE status='scheduled'` | — | cron log | — | cron disable |
| S13 Activity 폴링 | listId | GET 풀 재시도 | poll_status | 실패시 DB 미변경 | cron disable |

---

## G. 운영 체크리스트 (배포 직후)

1. `config/config.php` — `MARKETO_*` ID, `SEND_TEST_EMAIL_TO`, `APP_URL` 채워졌는가
2. 4개 cron이 Windows Task Scheduler에 등록되었는가 (`check_bulk_imports` 1분, 나머지 5~10분)
3. `TOKEN_CACHE_FILE` 경로의 쓰기 권한이 있는가
4. PHPUnit `vendor/bin/phpunit` 통과 — 토큰 빌더 회귀 방지
5. INV-02 검증: 같은 세그먼트의 두 캠페인에 동시에 결재 → 1개는 409
6. 5xx 시뮬레이션: Marketo POST 차단 시 캠페인이 `needs_manual_review` 또는 `failed`로 안착하는지
