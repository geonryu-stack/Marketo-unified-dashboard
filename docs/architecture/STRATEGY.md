# STRATEGY.md — UI/워크플로 개선 전략 브리핑

> 작성: 2026-05-20. 5개 도메인 담당(DB/ASSET/MKT/ORCH/INFRA) 합의 전략.
> 본 문서는 **시간 단축, 사고 예방, 의사결정 지원** 세 축으로 25개 개선안을 우선순위·의존성 매트릭스로 정리하고, 병렬 실행이 가능하도록 4개 스프린트로 묶었다.

## 0. 한 줄 요약

> "**사고 차단을 최우선으로 자기 zone 단독 작업(Sprint 0)**을 5명이 동시 진행하고, **관측·격리(Sprint 1)**, **결재 효율화(Sprint 2)**, **규모화(Sprint 3)** 순서로 cross-zone 작업을 단계적으로 합류시킨다."

## 1. 운영자 페인포인트 (선행 분석)

| 페인포인트 | 발생 빈도 | 사고 등급 | 현 우회법 | 해결 sprint |
|-----------|----------|---------|---------|-------------|
| 잘못된 토큰 값으로 발송 | 드물지만 치명 | ★★★ | 테스트메일 수동 확인 | S0 (C-TOKEN-VERIFY) |
| 결재 후 5분 안에 취소 불가 | 발생 시 100% 사고 | ★★★ | 없음 | S0 (5분 윈도) |
| 사내 DB 폭주/실종 | 분기당 1~2회 | ★★★ | 운영자 직감 | S0 (표본 + 드리프트 경고) |
| `needs_manual_review` 인지 지연 | 위험구간 실패 시 | ★★★ | 새로고침 폴링 | S1 (Slack 알림) |
| Marketo UI 따로 켜기 | 격리 시마다 | ★★ | 탭 전환 | S1 (EP 미러링) |
| 직전 회차 결과 비교 어려움 | 매주 | ★★ | 다른 캠페인 페이지 왕복 | S2 (코호트 뷰, 통합 결재 카드) |
| 제목/프리헤더 잘림 발견 늦음 | 발송 후 발견 | ★★ | 인박스 가서 보고 | S0 (길이 가이드) |
| 콘텐츠 4종 매번 새로 작성 | 매주 | ★ | 이전 캠페인 보고 손으로 | S2 (프리셋) |

## 2. 25개 개선안 우선순위 매트릭스

| zone | ★★★ (필수) | ★★ (가치 큼) | ★ (있으면 좋음) |
|------|------------|---------------|------------------|
| **DB** | ① 표본 미리보기+동의 가드<br>② 카운트 스냅샷+드리프트 경고 | ③ 필터 그룹 OR/NOT | ④ 사내 스키마 드리프트 자동검출 (+INFRA) |
| **ASSET** | ⑤ 라이브 인박스 미리보기<br>⑥ 길이 가이드+잘림 시뮬레이션 | ⑦ URL 검증+자동정규화<br>⑧ 콘텐츠 프리셋 | ⑨ 스크린샷 첨부 슬롯 (+INFRA) |
| **MKT** | ⑩ C-TOKEN-VERIFY<br>⑪ EP 상태 미러링 패널 | ⑫ C-SCHEDULE-ECHO<br>⑬ EP 충돌 사전 가시화 | ⑭ Bulk 진행률 rows/sec |
| **ORCH** | ⑮ 통합 결재 카드(직전회차+체크리스트)<br>⑯ needs_manual_review 격리 큐+알림<br>⑰ 5분 취소 윈도+카운트다운 | ⑱ 코호트 비교 뷰<br>⑲ KPI 대시보드 | (일괄 결재) |
| **INFRA** | ⑳ structured log + run_id<br>㉑ Slack/Email 알림 webhook | ㉒ DRY_RUN_MODE / READ_ONLY_MODE 킬스위치<br>㉓ status_history 테이블 | ㉔ 자동 백업/롤백 절차<br>㉕ 헬스체크 엔드포인트 |

## 3. 시너지 그룹 (서로 곱셈효과)

| 시너지 ID | 결합 | 효과 |
|----------|------|------|
| **G1. 사일런트 콘텐츠 실패 차단** | ⑩ C-TOKEN-VERIFY + ⑤ 라이브 미리보기 + ⑥ 길이 가이드 | 잘못된 발송이 운영자 화면-Marketo 양쪽에서 잡힘 |
| **G2. 학습 자산화** | ⑱ 코호트 뷰 + ② 카운트 스냅샷 + ⑲ KPI 대시보드 | 매주 의사결정의 근거 데이터화 |
| **G3. 격리 운영 자율화** | ⑯ 격리 큐+알림 + ⑪ EP 미러링 + ⑳ structured log | needs_manual_review 평균 해결시간 ↓↓ |
| **G4. 결재 단축** | ⑮ 통합 결재 카드 + ⑤ 미리보기 + ⑱ 코호트 | 1건당 결재 시간 ~3분 → 30초 |
| **G5. 마지막 안전망** | ⑰ 5분 취소 윈도 + ⑬ EP 충돌 사전 + ② 드리프트 경고 | 위험구간 진입 자체를 차단 |

## 4. 의존성 그래프 (병렬화 분석)

### 4.1 단독 가능 (자기 zone 안에서 끝남)

```
DB     ─► ① 표본 미리보기, ③ 필터 OR/NOT
ASSET  ─► ⑥ 길이 가이드, ⑦ URL 검증, ⑧ 프리셋(v1 JS 상수)
MKT    ─► ⑩ C-TOKEN-VERIFY (ScheduleRunner 분기 1개만 추가), ⑭ Bulk rows/sec
ORCH   ─► ⑰ 5분 취소 윈도
INFRA  ─► ⑳ structured log, ㉒ kill switches, ㉕ healthcheck
```

### 4.2 cross-zone 의존

```
② 드리프트 경고     : DB ──► ORCH (게이트 위치 합의)
④ 스키마 자동검출   : DB ──► INFRA (cron 등록, 알림)
⑤ 라이브 미리보기   : ASSET ──► MKT (선택: cloned email content API)
⑨ 스크린샷 슬롯     : ASSET ──► INFRA (저장경로) + DB (컬럼)
⑪ EP 미러링         : MKT ──► ORCH (detail.php 카드)
⑬ EP 충돌 사전      : MKT ──► DB (조인쿼리) + ORCH (new/edit 경고)
⑮ 통합 결재 카드    : ORCH ──► ASSET (미리보기 컴포넌트) + DB (직전회차 endpoint) + MKT (직전 send_stats)
⑯ 격리 큐+알림      : ORCH ──► INFRA (Slack webhook)
⑱ 코호트 뷰         : ORCH ──► DB (인덱스+쿼리) + MKT (KPI 적재)
⑲ KPI 대시보드      : ORCH ──► DB (㉓ status_history 신설)
㉑ Slack 알림        : INFRA ──► ORCH (트리거 지점)
```

## 5. 4-스프린트 실행 계획

각 스프린트는 1~2주 가정. 모든 zone이 동시 시작 가능.

### Sprint 0 — Safety Net (1주, 동시 5트랙)
*목표: 사고 가능성을 즉시 봉쇄*

| zone | 작업 | LOC 추정 | 머지 순서 |
|------|------|---------|----------|
| INFRA | ⑳ structured log + run_id 컬럼(job_logs/campaigns), ㉒ DRY_RUN_MODE config | 80 | 1 |
| MKT | ⑩ C-TOKEN-VERIFY(`getProgramTokens` 호출 + diff) | 60 | 2 |
| DB | ① 표본 미리보기 + 동의 가드 토글 | 100 | 3 |
| ORCH | ⑰ 5분 취소 윈도 + 카운트다운 UI | 120 | 4 |
| ASSET | ⑥ 길이 가이드, ⑦ URL 검증 (둘 다 JS 단독) | 60 | 5 |

**완료 기준**: 각 zone PHPUnit/수동 회귀 통과 + INV-04 시나리오 재현(C-TOKEN-VERIFY 차단 확인).

### Sprint 1 — Observability (1.5주, 5트랙 + 동기화 포인트 1개)
*목표: 문제 발생 시 1분 안에 인지*

| zone | 작업 | 의존 |
|------|------|------|
| INFRA | ㉑ Slack/Email webhook + ㉓ status_history 테이블 | — |
| MKT | ⑪ EP 미러링 API + ⑫ C-SCHEDULE-ECHO | S0의 ⑩ 반영 |
| DB | ② 카운트 스냅샷 + 드리프트 경고 | — |
| ORCH | ⑯ 격리 큐 UI + 알림 트리거 | INFRA ㉑ 완료 후 |
| ASSET | ⑨ 스크린샷 첨부 슬롯 | INFRA 저장경로 |

**동기화 포인트**: ㉑ webhook 머지 → ⑯ 격리 알림 연결 (2일 내).
**완료 기준**: needs_manual_review 전환 시 Slack에 30초 내 알림 + 격리 큐 페이지에 우선 표시.

### Sprint 2 — Workflow Optimization (2주, 통합 결재 카드 중심)
*목표: 매주 운영시간을 30분 → 10분으로*

| zone | 작업 | 의존 |
|------|------|------|
| ASSET | ⑤ 라이브 인박스 미리보기 컴포넌트 | — |
| DB | "직전 회차 요약" endpoint + ⑧ content_presets 테이블 | — |
| MKT | 직전 send_stats 노출 (이미 적재 중) | — |
| ORCH | ⑮ **통합 결재 카드 통합** + ⑱ 코호트 비교 뷰 + ⑲ KPI 대시보드 | 위 3개 합류 후 |
| INFRA | ㉔ 자동 백업 절차 + ㉕ healthcheck | — |

**동기화 포인트**: ASSET 컴포넌트 + DB endpoint + MKT 노출이 각자 main 머지 → ORCH가 ⑮에서 합침.
**완료 기준**: 1건 결재에 소요 시간 측정값 ≤ 60초 (현 추정 3분), 직전 회차 비교 자동.

### Sprint 3 — Scale & Templates (2~3주, 선택)
*목표: 규모화 + 정성적 효율*

| zone | 작업 |
|------|------|
| DB | ③ 필터 그룹 OR/NOT (스키마 v2 + 백워드 호환) |
| DB | ④ 사내 스키마 드리프트 자동검출 cron |
| ORCH | 발송 그룹별 캘린더 뷰 (segments.is_recurring 활용) |
| MKT | ⑭ Bulk 진행률 정밀화 |
| ASSET | ⑧ 콘텐츠 프리셋 v2 (DB 기반) |

## 6. RACI 매트릭스 (스프린트 통합)

| 항목 | DB | ASSET | MKT | ORCH | INFRA |
|------|----|----|----|----|----|
| S0 사고 차단 | R(①) | R(⑥⑦) | R(⑩) | R(⑰) | R(⑳㉒), C(전체) |
| S1 관측·격리 | R(②) | R(⑨) | R(⑪⑫) | R(⑯) | R(㉑㉓), A |
| S2 결재 효율화 | C(직전회차) | C(미리보기) | C(노출) | **R/A(⑮)**, R(⑱⑲) | R(㉔㉕) |
| S3 규모화 | R(③④) | R(⑧v2) | R(⑭) | R(캘린더) | C(④cron) |

R=Responsible, A=Accountable, C=Consulted

## 7. 핵심 리스크 & 완화

| 리스크 | 영향 | 완화 |
|--------|-----|------|
| MarketoAPI 시그니처 변경 충돌 | 호출자 다수 회귀 | S0 시작 전 MKT의 신규 메서드 시그니처를 본 문서 §8에 등록 |
| ORCH 통합 결재 카드 ⑮가 S2 끝까지 지연 | S2 효과 무산 | S2 시작 시 ⑤⑧+직전회차 endpoint가 첫 주 끝에 머지 완료되도록 daily 동기화 |
| status_history 신설 마이그레이션 위험 | DB write 부하 | S1 첫 작업으로 ㉓ 마이그레이션 → 운영 가벼운 시간대 적용 |
| Slack webhook URL 비밀관리 | 노출 위험 | config/config.php(.gitignore) + INFRA가 secrets rotation 정책 마련 |

## 8. 안정 API 등록 (S0 시작 전 합의)

- `MarketoAPI::getProgramTokens(int $programId): array` (신규, GET, 재시도 안전)
- `MarketoAPI::getEmailProgramSnapshot(int $epId): array` (신규, GET)
- `/api/marketo/ep-lock-status?ep_id=` (READ ONLY)
- `/api/campaigns/{id}/previous-cohort` (DB)
- `/api/segments/{id}/cohort` (ORCH)

> 위 5개 시그니처는 S2 끝까지 동결. 변경 시 STRATEGY.md PR로 합의.

## 9. KPI (성과 측정)

| 지표 | 베이스라인 (추정) | S0 후 | S2 후 |
|------|---------|-------|-------|
| 캠페인 1건 결재 시간 | ~3분 | 2분 | < 60초 |
| 잘못된 토큰값 발송 사고 | 분기 0~1 | 0 | 0 |
| needs_manual_review 평균 해결시간 | 30분~수시간 | 동일 | 5분 |
| 사내 DB 폭주 사고 | 분기 1~2 | 0 (드리프트 경고) | 0 |
| 매주 운영시간 | ~30분 | ~25분 | ~10분 |
