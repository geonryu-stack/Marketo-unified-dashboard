# CONTEXT_MAP.md — 병렬 작업용 컨텍스트 맵

> 여러 담당자가 동시에 작업해도 충돌 없이 진행할 수 있도록 **모듈 경계 / 계약 / 소유권 / 안전영역**을 정의.
> 핵심 질문: "내가 X 파일을 만지면, 누구의 일에 영향을 주는가?"

## 0. 5개 직무 (롤)

| 코드 | 롤 | 주력 산출물 |
|------|-----|------------|
| **DB** | DB-segment 담당 | 사내 DB 필터 정의, 세그먼트 메타, 마이그레이션 |
| **ASSET** | Asset-composer 담당 | 이메일 콘텐츠, 토큰, 테스트 발송, UI 컴포저 |
| **MKT** | Marketo-sync 담당 | Marketo REST/Bulk API, 토큰 동기화, EP 예약 |
| **ORCH** | Campaign-orchestrator 담당 | 결재 워크플로, CAS, 상태머신, cron 오케스트레이션 |
| **INFRA** | Infra/Ops 담당 | Apache/XAMPP, cron, 모니터링, config |

---

## 1. 소유권 매트릭스 (파일 → 롤)

| 경로 | 1차 소유 | 2차 (협의 필요) |
|------|---------|----------------|
| `src/InternalDB.php` | DB | — |
| `src/helpers.php::get_field_defs()` | DB | ASSET (UI 셀렉터 영향) |
| `src/helpers.php::build_where_clause()` | DB | ORCH |
| `src/helpers.php::build_campaign_tokens()` | ASSET | MKT |
| `src/helpers.php::mime_header_value/html_body_value()` | ASSET | MKT |
| `src/helpers.php::status_*()` | ORCH | ASSET (UI 색상) |
| `src/Marketo/MarketoAPI.php` | MKT | INFRA (retry 정책) |
| `src/Marketo/MarketoBulkImport.php` | MKT | INFRA |
| `src/ScheduleRunner.php` | ORCH | MKT (API 호출), DB (extract) |
| `src/DB.php`, `src/Router.php` | INFRA | ORCH |
| `api/segments.php` | DB | ORCH |
| `api/internal-db.php` | DB | — |
| `api/campaigns.php` | ORCH | ASSET (test flow), MKT (cancel) |
| `api/schedules.php` | ORCH | MKT |
| `api/marketo.php` | MKT | — |
| `cron/run_due_campaigns.php` | ORCH | INFRA |
| `cron/check_bulk_imports.php` | ORCH | MKT (Bulk status), INFRA |
| `cron/sync_sent_campaigns.php` | ORCH | INFRA |
| `cron/check_sent_campaigns.php` | ORCH | — (`sync_sent_campaigns`의 대안 구현) |
| `cron/check_sent_activities.php` | ORCH | MKT (Activity API) |
| `pages/segments/*` | DB | ASSET |
| `pages/campaigns/*` | ORCH | ASSET (UI), MKT (cancel 안내) |
| `pages/schedules/*` | ORCH | MKT |
| `assets/js/*` | ASSET | ORCH (워크플로 액션) |
| `config/config.example.php` | INFRA | 모든 롤 (값 채움) |
| `sql/schema.sql`, `sql/migrations/*.sql` | INFRA | 컬럼 변경 시 1차 소유 롤 협의 필수 |
| `tests/Unit/*` | 1차 소유와 동일 | — |

---

## 2. 계약 표면 (Contracts) — 변경시 사전 합의 필수

### 2.1 DB 스키마 계약

| 테이블 | 변경 영향 |
|--------|-----------|
| `segments` | DB+ORCH+MKT 모두 영향. 마이그레이션 PR 작성 시 3명 리뷰 권장 |
| `campaigns` | ORCH 1차. status 컬럼 값 추가/삭제 시 ASSET(UI)·MKT(retry)도 영향 |
| `job_logs` | ORCH 1차. `step` 명 추가 시 UI 표시 영향 (ASSET) |
| `groups`, `send_schedules` | ORCH+MKT. 그룹 캘린더 기능. |

### 2.2 함수 시그니처 계약 (안정 API)

| 함수 | 호출자 | 변경 영향 |
|------|--------|-----------|
| `MarketoAPI::*` | ScheduleRunner, api/*, cron/* | MKT가 시그니처 바꾸면 호출자 전부 회귀 위험 |
| `ScheduleRunner::run_campaign_schedule(array $c, callable $log)` | `api/campaigns.php:approve`, `cron/run_due_campaigns.php` | ORCH 변경 시 양쪽 동기 수정 |
| `ScheduleRunner::finalize_campaign_schedule(array $c, array $seg, callable $log)` | 위 + `cron/check_bulk_imports.php` | 동일 |
| `build_campaign_tokens(array $c): array` | ASSET (생성/주입 양쪽) | 토큰 키 추가 = Marketo Email Template 동시 수정 필요 (MKT) |
| `assert_readonly(string $sql)` | InternalDB | DB. 절대 약화 금지 (INV-01) |

### 2.3 외부 계약

| 외부 | 계약 |
|------|------|
| Marketo REST | OAuth client_credentials, `/rest/v1`, `/asset/v1`, `/bulk/v1` |
| Marketo Activity Type ID | 6=Sent, 7=Delivered, 11=SoftBounce, 12=HardBounce |
| 사내 DB | SELECT 전용, 테이블명 `INTERNAL_DB_TABLE` 환경값, 이메일 필드 `INTERNAL_DB_EMAIL_FIELD` |

---

## 3. 병렬 작업 안전영역 (Safe Zones)

> 같은 색의 영역은 **동시 작업 안전**. 다른 색이 만나는 지점은 사전 sync 필요.

```
┌───────────────────────────────────────────────────────────────┐
│ Zone A — Marketo Adapter                                       │
│  ▸ src/Marketo/MarketoAPI.php (메서드 추가)                              │
│  ▸ src/Marketo/MarketoBulkImport.php                                   │
│  ▸ api/marketo.php (read-only 리소스 추가)                       │
│  ※ 메서드 시그니처는 변경 금지 (Zone C와 충돌)                     │
├───────────────────────────────────────────────────────────────┤
│ Zone B — DB / 필터 / UI 세그먼트                                 │
│  ▸ src/helpers.php::get_field_defs / build_where_clause        │
│  ▸ api/segments.php, api/internal-db.php                       │
│  ▸ pages/segments/*                                            │
│  ▸ sql/migrations/*.sql (segments 테이블만)                       │
│  ※ segments 테이블에 컬럼 추가 시 ScheduleRunner 안전 확인         │
├───────────────────────────────────────────────────────────────┤
│ Zone C — 캠페인 오케스트레이션 / 결재 / 상태머신                   │
│  ▸ src/ScheduleRunner.php                                      │
│  ▸ api/campaigns.php                                           │
│  ▸ cron/run_due_campaigns.php, check_bulk_imports.php          │
│  ▸ cron/sync_sent_campaigns.php, check_sent_activities.php     │
│  ▸ src/helpers.php::status_*                                   │
│  ※ Zone A의 시그니처 변경에 가장 취약                              │
├───────────────────────────────────────────────────────────────┤
│ Zone D — 이메일 콘텐츠 / 토큰 / 테스트 발송 UI                     │
│  ▸ src/helpers.php::build_campaign_tokens / mime/html_*        │
│  ▸ pages/campaigns/new.php / edit.php / detail.php (콘텐츠 카드)│
│  ▸ assets/js/campaign.js (체크리스트, 모달)                      │
│  ▸ assets/css/style.css                                        │
│  ※ 토큰 키 추가 시 Marketo Email Template 동기 수정 (Zone A)      │
├───────────────────────────────────────────────────────────────┤
│ Zone E — 인프라 / config / 라우팅 / 마이그레이션                   │
│  ▸ index.php, src/Router.php, src/DB.php                       │
│  ▸ config/config.example.php                                   │
│  ▸ .htaccess, phpunit.xml.dist                                 │
│  ※ 라우팅 추가/삭제는 ORCH·ASSET·MKT 영향. 변경 전 모두 통지       │
└───────────────────────────────────────────────────────────────┘
```

### 3.1 동시 진행 가능 매트릭스

|  | A.Marketo | B.DB/Seg | C.Orch | D.Asset | E.Infra |
|--|-----------|----------|--------|---------|---------|
| **A** | — | ✅ | ⚠️ 시그니처 동기 | ✅ | ✅ |
| **B** | ✅ | — | ⚠️ filters 키 동기 | ✅ | ⚠️ migration |
| **C** | ⚠️ | ⚠️ | — | ⚠️ status 키 동기 | ⚠️ migration |
| **D** | ✅ | ✅ | ⚠️ | — | ✅ |
| **E** | ✅ | ⚠️ | ⚠️ | ✅ | — |

✅=안전 / ⚠️=사전 공지(브랜치 머지 순서 합의) / ❌=동시 변경 금지

---

## 4. 변경 영향 점검표 (Change Impact Checklist)

PR 작성자가 셀프 체크:

- [ ] **DB 스키마**: 컬럼 추가/타입 변경 시 모든 SELECT/INSERT/UPDATE 호출부 검색
- [ ] **status 값**: 추가/이름 변경 시 `status_label`, `status_badge_class`, sibling CAS 차단 목록, UI 카드, cron 쿼리
- [ ] **MarketoAPI 메서드**: 시그니처 변경 시 ScheduleRunner / cron / api/marketo.php 동기
- [ ] **`build_campaign_tokens` 키**: 추가 시 Marketo Email Template `my.NewKey` 사전 추가
- [ ] **field_defs**: 필드 추가/삭제 시 사내 DB 컬럼 존재 확인, 기존 segments.filters JSON 호환
- [ ] **재시도 정책**: HTTP 메서드별 안전성 재확인 (POST/DELETE 5xx 재시도 금지)
- [ ] **CAS 상태 목록**: 활성 상태(`scheduled / scheduling / bulk_* / needs_manual_review`)에 새 상태 추가시 sibling 목록 동기
- [ ] **마이그레이션**: idempotent 작성 (IF NOT EXISTS / WHERE조건)
- [ ] **Cron 영향**: 잡 LIMIT, 주기, CAS 패턴 변동
- [ ] **테스트**: `tests/Unit/CampaignFunctionsTest.php`에 회귀 케이스 추가

---

## 5. 의사소통 프로토콜

### 5.1 PR 라벨 권고

| 라벨 | 의미 | 리뷰어 |
|------|------|--------|
| `zone:A-marketo` | Marketo adapter 변경 | MKT |
| `zone:B-db` | DB/세그먼트 변경 | DB |
| `zone:C-orch` | 오케스트레이션 변경 | ORCH (+영향 zone) |
| `zone:D-asset` | 콘텐츠/UI 변경 | ASSET |
| `zone:E-infra` | 인프라/config 변경 | INFRA + 모두 통지 |
| `cross-zone` | 2개 이상 zone 동시 변경 | 영향 zone 전원 |
| `migration` | sql/migrations/*.sql 포함 | INFRA 필수 |
| `behavior-change` | 가시적 운영 동작 변경 | 운영 담당자 1명 추가 |

### 5.2 사전 합의 트리거

다음 변경은 **반드시 사전 합의**(comment, 짧은 ADR, Slack):
1. campaigns 또는 segments 테이블 스키마
2. MarketoAPI/MarketoBulkImport public 메서드 시그니처
3. campaigns.status 값 (sibling 충돌 목록 영향)
4. config/config.example.php에 새 상수 추가
5. cron 주기·LIMIT 변경
6. 라우팅 추가/삭제

### 5.3 머지 순서 (일반 룰)

```
1. Zone E (config/schema 추가)  ← 먼저 안전하게
2. Zone A (Marketo 메서드)
3. Zone B (필드/세그먼트)
4. Zone C (오케스트레이션)        ← A/B 결과 흡수
5. Zone D (UI/콘텐츠)             ← 마지막 (체감 변화 큼)
```

---

## 6. 환경별 가시성

| 환경 | URL | 데이터 위험 | 권장 작업 |
|------|-----|------------|----------|
| 로컬 (XAMPP) | `http://localhost/marketo-send-automation/` | 없음 | 모든 zone |
| 라이브 테스트 | 동일 | INTERNAL_DB_BYPASS_LEADS로 테스트 주소만 | Zone D 검증 |
| 운영 | (사내 서버) | **사내 사용자 실발송 발생** | Zone C/E는 cron 영향 큼 → off-hours 배포 |

---

## 7. 자주 묻는 충돌 시나리오

| 충돌 | 해결 |
|------|------|
| A가 MarketoAPI 메서드 추가 + C가 ScheduleRunner에서 그 메서드 사용 | E → A → C 머지 순서. A 먼저 main 반영. |
| B가 새 필터 필드 추가 + D가 UI에서 표시 | B(field_defs) → D(UI) 순서 |
| C가 새 status 도입 + D가 카드 UI 추가 + A가 status 보고 분기 | C 먼저, A·D는 동시 가능하지만 머지는 C 이후 |
| E가 migration + 1차 소유 롤이 컬럼 사용 | 1차 소유 롤이 migration도 작성 (ownership 일치) |

---

## 8. 빠른 참고 — "이 변경, 누구한테 물어야 하나?"

```
변경: My Token 키 추가         →  ASSET + MKT
변경: 세그먼트 필터 필드 추가   →  DB
변경: status에 'paused' 추가   →  ORCH + ASSET + INFRA(cron)
변경: Bulk 임계값 조정         →  INFRA + ORCH
변경: 사내 DB 테이블명 변경    →  DB + INFRA(config)
변경: Marketo retry 정책 수정  →  MKT + ORCH(영향분석)
변경: cron 주기 단축           →  INFRA + ORCH
변경: needs_manual_review 동작 →  ORCH (격리 룰의 핵심)
```
