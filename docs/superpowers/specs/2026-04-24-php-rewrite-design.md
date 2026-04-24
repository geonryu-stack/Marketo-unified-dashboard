# PHP 전면 재작성 설계 스펙

**Date:** 2026-04-24  
**Status:** Approved  
**Project:** marketo-send-automation → PHP + MySQL + Apache (XAMPP)

---

## 1. 배경 및 목적

현재 Next.js(TypeScript) + SQLite로 구성된 Marketo 캠페인 자동화 도구를 회사 표준 개발환경(PHP + MySQL + Apache / XAMPP)에 맞게 전면 재작성한다. Vercel 배포 문제를 해결하고, 사내 PHP 개발자가 유지보수할 수 있는 구조로 전환한다.

---

## 2. 확정 스택

| 구성 요소 | 사양 |
|-----------|------|
| 웹 서버 | Apache (XAMPP) |
| 언어 | PHP 8.x |
| 앱 DB | MySQL (XAMPP 내장) |
| 사내 DB | MySQL (회사 읽기전용 DB) |
| 라우팅 | index.php 단일 진입점 + .htaccess |
| 프론트엔드 | PHP 서버 렌더링 + 바닐라 JS (AJAX 최소화) |
| 외부 API | Marketo REST API (PHP curl) |
| 스케줄링 | Windows 작업 스케줄러 → PHP CLI |

---

## 3. 포함/제외 기능

| # | 기능 | 상태 |
|---|------|------|
| 1 | 세그먼트 관리 (필터 빌더 + 사내 DB 대상자 추출) | ✅ 포함 |
| 2 | 캠페인 관리 (Phase 1/2 실행 + 승인 워크플로우) | ✅ 포함 |
| 3 | 에셋 라이브러리 | ❌ 제외 (Marketo에서 직접 관리) |
| 4 | 발송 스케줄 (주간 대시보드) | ✅ 포함 |
| 5 | Marketo API 연동 | ✅ 포함 |
| 6 | Cron 자동 실행 | ✅ 포함 |
| 7 | 사내 DB 연결 (읽기전용 MySQL) | ✅ 포함 |

---

## 4. 폴더 구조

```
htdocs/marketo-automation/
├── index.php                    # 단일 진입점 — 모든 HTTP 요청 수신
├── .htaccess                    # RewriteRule: 모든 요청 → index.php
├── config/
│   └── config.php               # DB/Marketo 연결 정보 상수 정의 (gitignore)
│   └── config.example.php       # 연결 정보 템플릿 (git 추적)
├── src/
│   ├── Router.php               # URL 패턴 → 핸들러 함수 매핑
│   ├── DB.php                   # 앱 MySQL PDO 싱글턴
│   ├── InternalDB.php           # 사내 DB MySQL PDO 싱글턴 (읽기전용)
│   ├── MarketoAPI.php           # Marketo REST API curl 래퍼 클래스
│   └── helpers.php              # 공통 유틸 함수 (json_response, uuid, 날짜 포맷 등)
├── pages/                       # PHP 서버 렌더링 페이지
│   ├── layout.php               # 공통 헤더/푸터 include 템플릿
│   ├── home.php                 # 대시보드 홈
│   ├── segments/
│   │   ├── index.php            # 세그먼트 목록
│   │   ├── new.php              # 세그먼트 생성
│   │   └── edit.php             # 세그먼트 편집
│   ├── campaigns/
│   │   ├── index.php            # 캠페인 목록
│   │   ├── new.php              # 캠페인 생성
│   │   └── detail.php           # 캠페인 상세 + 실행 로그
│   └── schedules/
│       └── index.php            # 주간 발송 스케줄 대시보드
├── api/                         # JSON 응답 엔드포인트 (AJAX 전용)
│   ├── segments.php             # GET/POST/PUT/DELETE
│   ├── campaigns.php            # GET/POST + run/confirm/approve/reject/cancel
│   ├── schedules.php            # GET/POST/PUT
│   ├── internal-db.php          # 필드 목록 + 대상자 미리보기
│   └── marketo.php              # Marketo 이메일/리스트 조회 프록시
├── cron/
│   └── run_due_campaigns.php    # Windows 작업 스케줄러 실행 대상 (PHP CLI)
├── assets/
│   ├── css/
│   │   └── style.css            # 공통 스타일 (Bootstrap CDN 보조)
│   └── js/
│       ├── segment-builder.js   # 필터 조건 추가/삭제 + 미리보기 AJAX
│       └── campaign.js          # 캠페인 실행 버튼 + 로그 폴링 AJAX
└── sql/
    └── schema.sql               # MySQL 초기 스키마 + 기본 데이터 시딩
```

---

## 5. DB 스키마 설계 (MySQL)

SQLite 스키마를 MySQL로 변환. `asset_library` 테이블 제거.

### 테이블 목록
- `segments` — 세그먼트 (필터 JSON, Marketo 연결 정보, 반복 발송 설정)
- `campaigns` — 캠페인 (상태 머신, Marketo 연결 정보, 실행 시각)
- `job_logs` — 캠페인 실행 단계별 로그
- `groups` — 발송 그룹 (Active A/B, FP/NP Active)
- `send_schedules` — 주간 발송 스케줄

### SQLite → MySQL 변환 규칙
- `TEXT PRIMARY KEY` → `VARCHAR(36) PRIMARY KEY`
- `INTEGER` → `INT`
- `TEXT` → `TEXT` 또는 `VARCHAR(255)`
- 모든 테이블: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`
- JSON 필터: `TEXT` 컬럼에 JSON 문자열로 저장 (MySQL 5.7+ JSON 타입 사용 가능하나 호환성을 위해 TEXT 유지)

---

## 6. 라우팅 설계

`.htaccess`가 모든 요청을 `index.php`로 전달. `Router.php`가 URL 패턴 매칭.

### 페이지 라우트
| URL | 핸들러 |
|-----|--------|
| `GET /` | pages/home.php |
| `GET /segments` | pages/segments/index.php |
| `GET /segments/new` | pages/segments/new.php |
| `GET /segments/{id}/edit` | pages/segments/edit.php |
| `GET /campaigns` | pages/campaigns/index.php |
| `GET /campaigns/new` | pages/campaigns/new.php |
| `GET /campaigns/{id}` | pages/campaigns/detail.php |
| `GET /schedules` | pages/schedules/index.php |

### API 라우트 (AJAX)
| Method + URL | 파일 |
|--------------|------|
| `GET/POST /api/segments` | api/segments.php |
| `GET/PUT/DELETE /api/segments/{id}` | api/segments.php |
| `GET/POST /api/campaigns` | api/campaigns.php |
| `POST /api/campaigns/{id}/run` | api/campaigns.php |
| `POST /api/campaigns/{id}/confirm` | api/campaigns.php |
| `POST /api/campaigns/{id}/approve` | api/campaigns.php |
| `POST /api/campaigns/{id}/reject` | api/campaigns.php |
| `POST /api/campaigns/{id}/cancel` | api/campaigns.php |
| `GET /api/campaigns/{id}/logs` | api/campaigns.php |
| `GET/POST/PUT /api/schedules` | api/schedules.php |
| `POST /api/schedules/{id}/test` | api/schedules.php |
| `POST /api/schedules/{id}/schedule` | api/schedules.php |
| `GET /api/internal-db/fields` | api/internal-db.php |
| `POST /api/internal-db/preview` | api/internal-db.php |
| `GET /api/marketo/emails` | api/marketo.php |
| `GET /api/marketo/lists` | api/marketo.php |
| `GET /api/marketo/campaigns` | api/marketo.php |
| `GET /api/campaigns/{id}/approve-via-link` | api/campaigns.php |
| `GET /api/campaigns/{id}/reject-via-link` | api/campaigns.php |
| `POST /api/campaigns/{id}/reset-to-draft` | api/campaigns.php |

---

## 7. Marketo API 연동

`MarketoAPI.php` 클래스가 현재 `lib/marketo.ts`의 모든 함수를 PHP curl로 구현.

### 주요 메서드
- `getAccessToken()` — OAuth2 토큰 발급 (config/ 폴더 내 파일 캐시로 만료 전까지 재사용, /tmp 불사용)
- `upsertLeads(array $emails)` — 리드 업서트
- `getListLeadIds(int $listId)` — Static List 현재 멤버 ID 조회
- `addLeadsToList(int $listId, array $leadIds)` — 리스트에 리드 추가
- `removeLeadsFromList(int $listId, array $leadIds)` — 리스트에서 리드 제거
- `setProgramMyTokens(int $programId, array $tokens)` — My Token 주입
- `sendSampleEmail(int $emailId, string $toEmail)` — 테스트 메일 발송
- `scheduleEmailProgram(int $programId, string $datetime)` — Email Program 예약
- `unapproveEmailProgram(int $programId)` — Email Program unapprove (취소)
- `buildEpTokenPayload(array $asset, array $campaign)` — My Token 페이로드 배열 생성 헬퍼

---

## 8. Cron 자동 실행

### 구조
`cron/run_due_campaigns.php`가 현재 `confirmed` 상태이고 `scheduled_at <= NOW()`인 캠페인을 조회해 Phase 1을 순차 실행한다.

Phase 1 파이프라인 (현재 Next.js와 동일):
1. 사내 DB에서 세그먼트 필터로 대상자 이메일 추출
2. Marketo 리드 업서트
3. Static List 갱신 (기존 제거 → 신규 추가)
4. Email Program My Token 주입
5. 테스트 메일 발송 → status = `awaiting_approval`

### Windows 작업 스케줄러 설정 (문서화)
```
작업 이름: MarketoCron
트리거: 매일, 1분마다 반복
동작: php.exe C:\xampp\htdocs\marketo-automation\cron\run_due_campaigns.php
```

---

## 9. 보안 고려사항

- `config/config.php` — `.gitignore` 추가, Git에 절대 커밋하지 않음
- 모든 DB 쿼리 — PDO prepared statements (SQL Injection 방지)
- API 엔드포인트 — POST 요청 시 `Content-Type: application/json` 검증
- 사내 DB — `InternalDB.php`에서 SELECT 쿼리만 허용 (assertReadOnly 동일 로직 유지)
- SQL 필터 빌더 — FIELD_DEFS 화이트리스트 기반 컬럼명 검증 유지 (미정의 필드 throw 로직 동일하게 이식)
- 승인 링크 토큰 — HMAC-SHA256 서명 + timing-safe 비교 (`hash_equals()`) 유지
- 에러 응답 — DB 스키마·내부 경로 등 민감 정보 노출 금지, 프로덕션에서 상세 에러 숨김
- MySQL 동시성 — 캠페인 실행 시 `SELECT ... FOR UPDATE` 또는 트랜잭션 격리 수준 `REPEATABLE READ`로 동시 실행 방지 (SQLite `BEGIN IMMEDIATE` 대체)

---

## 10. 현재 Next.js 코드베이스 처리

PHP 재작성이 완료되면:
- Next.js 코드 전량 삭제
- `package.json`, `node_modules`, `.next` 등 Node.js 아티팩트 제거
- `data/app.db` SQLite 파일 삭제
- Git에 새 PHP 프로젝트로 커밋

---

## 11. 구현 순서 (서브시스템별)

1. **기반** — config, DB, Router, helpers, layout, schema.sql
2. **세그먼트 관리** — pages + api + segment-builder.js
3. **캠페인 관리** — pages + api + campaign.js + Phase 1/2 로직
4. **Marketo API** — MarketoAPI.php 전체 구현
5. **사내 DB 연동** — InternalDB.php + buildWhereClause PHP 이식
6. **발송 스케줄** — pages + api
7. **Cron** — run_due_campaigns.php
8. **정리** — Next.js 파일 제거, README 업데이트
