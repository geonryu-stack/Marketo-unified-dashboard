# marketo-send-automation — 아키텍처 개요

> 본 문서는 `PIPELINE.md`, `HARNESS.md`, `CRITICS.md`, `CONTEXT_MAP.md` 의 진입점.
> 작성: 2026-05-20 (PHP 재작성 완료 후 운영 안정화 단계 기준)

## 1. 한 줄 요약

사내 DB의 사용자 세그먼트를 추출해 → Marketo Static List에 업로드 → My Token을 통해 Email Program 콘텐츠를 주입 → RTZ(Recipient Time Zone) 예약 발송 → Activity API로 결과를 수집하는 **단일 파이프라인 자동화 시스템**.

## 2. 현재 스택 (2026-05-20)

| 레이어 | 기술 | 비고 |
|--------|------|------|
| 웹 서버 | Apache (XAMPP) | `.htaccess` 단일 진입 라우팅 |
| 런타임 | PHP 8.x | 사내 표준 |
| 앱 DB | MySQL 8.x (`marketo_automation`) | InnoDB, utf8mb4 |
| 사내 DB | MySQL 백업 (읽기전용) | `assert_readonly()` 강제 |
| 외부 API | Marketo REST + Bulk Import | OAuth client_credentials |
| 큐/잡 | OS Cron (Windows Task Scheduler) | 폴링 기반 |
| 프론트 | Bootstrap 5 + Vanilla JS | 빌드 파이프라인 없음 |
| 테스트 | PHPUnit 10 | `tests/Unit/` |

## 3. 4계층 도식

```
┌──────────────────────────────────────────────────────────────────┐
│  L1. 비즈니스 (운영자 UI)                                          │
│  - Segment 정의 / Campaign 생성 / 결재 / 그룹 캘린더               │
│  pages/*                                                          │
├──────────────────────────────────────────────────────────────────┤
│  L2. 애플리케이션 (API + 도메인 함수)                              │
│  - 라우팅, 권한 게이트, 트랜잭션 + CAS                              │
│  api/*  +  src/ScheduleRunner.php  +  src/helpers.php             │
├──────────────────────────────────────────────────────────────────┤
│  L3. 외부 연동 (Adapter)                                          │
│  - Marketo REST/Bulk, Internal DB SELECT 전용                     │
│  src/Marketo/MarketoAPI.php  +  src/Marketo/MarketoBulkImport.php │
│  + src/InternalDB.php                                             │
├──────────────────────────────────────────────────────────────────┤
│  L4. 시간 기반 자동화 (Cron 워커)                                  │
│  - 만기 캠페인 처리, Bulk 폴링, sent 전환, Activity 수집           │
│  cron/*                                                           │
└──────────────────────────────────────────────────────────────────┘
```

## 4. 문서 가이드

- **PIPELINE.md** — 캠페인 1건이 거치는 13단계의 정규 파이프라인 (입력/출력/실패 모드)
- **HARNESS.md** — 각 단계에 적용되는 가드레일·재시도·관측·롤백·킬스위치 정의
- **CRITICS.md** — 단계 사이에 끼는 검증/판단 게이트 정의 (정량 + 정성 + 휴먼)
- **CONTEXT_MAP.md** — 4개 직무(운영·DB·Marketo·인프라)의 책임 경계, 병렬작업 안전영역

## 5. 핵심 불변식 (절대 위반 금지)

| ID | 불변식 | 위반시 결과 |
|----|--------|------------|
| INV-01 | 사내 DB는 SELECT만 (`assert_readonly`) | RuntimeException |
| INV-02 | 한 세그먼트(=EP)에 동시에 1개 캠페인만 점유 (`scheduled\|scheduling\|bulk_*\|needs_manual_review`) | CAS 409 또는 cron 건너뜀 |
| INV-03 | 발송 일시 = 현재 + 16h 이상 | 생성·저장 시 400 |
| INV-04 | EP 변경 위험구간 실패 → `needs_manual_review` 격리, sibling 차단 유지 | 운영자 수동 해제 필요 |
| INV-05 | Bulk Import 부분 실패(`numOfRowsFailed > 0`) → 자동 진행 차단 | status=failed, 수동 보정 |
| INV-06 | `marketo_email_program_id`는 EP 변경 위험구간 진입 *직전* DB 저장 | cancel 누락(fake cancel) 방지 |
| INV-07 | Marketo POST/DELETE는 5xx/네트워크 오류 시 재시도 금지 | 중복 부작용 방지 |
