# marketo-send-automation

> 사내 DB → Marketo Static List 업로드 → My Token 동기화 → Smart Campaign 예약 → 발송 결과 폴링/격리까지 일체를 *운영자(마케터) 1명* 이 안전하게 수행할 수 있도록 자동화한 PHP 8.x + MySQL + Apache (XAMPP) 시스템.

---

## 시작하기

| 역할 | 진입 문서 |
|---|---|
| **외부 개발자 — 처음 받는 경우** | [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) — XAMPP 설치 → DB 마이그레이션 → config → cron 등록 step-by-step |
| 외부 개발자 — 결정사항·한계 이해 | [`docs/HANDOFF.md`](docs/HANDOFF.md) — 본 PR 의 결정·SEV1 사고 회고·남은 결함 |
| **운영자 — 라이브 발송** | [`docs/SOP_LIVE_SEND.md`](docs/SOP_LIVE_SEND.md) — 발송 SOP + 6 키 체크리스트 |
| 시스템 구조 이해 | [`docs/architecture/OVERVIEW.md`](docs/architecture/OVERVIEW.md) — 4 계층 도식, 7 핵심 불변식 |
| 파이프라인 상세 | [`docs/architecture/PIPELINE.md`](docs/architecture/PIPELINE.md) — 13 stages |
| 가드/재시도/관측 매트릭스 | [`docs/architecture/HARNESS.md`](docs/architecture/HARNESS.md) |

---

## 핵심 정보

- **스택**: PHP 8.x + MySQL + Apache (XAMPP). [`docs/DEV_ENVIRONMENT.md`](docs/DEV_ENVIRONMENT.md) 참고.
- **현재 테스트**: `vendor/bin/phpunit` → **226 tests / 586 assertions OK** (2026-05-27 기준).
- **마지막 SEV1 사고**: 2026-05-22. RCA 와 4 가지 fix 는 [`docs/HANDOFF.md`](docs/HANDOFF.md) §2 참고.

---

## 빠른 테스트

```bash
# 의존성 설치
composer install

# 회귀 테스트 전체 실행
vendor/bin/phpunit

# PHP syntax check
for f in $(find src api cron pages -name "*.php"); do php -l "$f"; done
```

---

## 디렉토리 구조

```
marketo-send-automation/
├── api/                 # JSON REST API 엔드포인트 (캠페인/세그먼트/대시보드/Marketo 사용량)
├── assets/              # JS / CSS 자산
├── config/              # config.example.php — 복사 후 config.php 로 사용
├── cron/                # 백그라운드 자동화 (Windows Task Scheduler / Linux crontab)
├── docs/                # 본 문서들
│   ├── architecture/    # 내부 매뉴얼 (OVERVIEW/PIPELINE/HARNESS/CRITICS/CONTEXT_MAP/STRATEGY)
│   ├── DEPLOYMENT.md    # 외부 개발자 셋업 가이드
│   ├── HANDOFF.md       # 결정사항 + 알려진 한계 + 남은 결함
│   ├── SOP_LIVE_SEND.md # 운영자 라이브 발송 SOP
│   └── DEV_ENVIRONMENT.md
├── pages/               # PHP 페이지 템플릿 (캠페인 상세, 격리 큐, 대시보드 등)
├── sql/
│   ├── schema.sql       # 메인 스키마 (신규 환경 초기 적용)
│   └── migrations/      # 시간순 누적 마이그레이션 — DEPLOYMENT.md §2-3 순서대로
├── src/                 # 도메인 로직
│   ├── Marketo/         # Marketo REST 클라이언트 (MarketoAPI / MarketoBulkImport / MarketoApiUsage)
│   ├── SendCap.php      # 리드별 cap (priority 차등)
│   ├── Suppression.php  # VVIP 우선순위 NOT IN 결합
│   └── ScheduleRunner.php  # 캠페인 1건 전체 발송 흐름 오케스트레이터
├── tests/Unit/          # phpunit 회귀 가드
├── index.php            # 모든 HTTP 요청 라우터
└── README.md            # 본 문서
```

---

## 라이선스

내부 프로젝트 — 사외 공개·배포 금지.
