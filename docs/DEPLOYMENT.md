# DEPLOYMENT.md — 외부 개발자 인수인계 배포 가이드

> 본 문서는 **본 프로젝트를 처음 받는 외부 개발자**가 라이브 발송 가능한 상태까지 도달하기 위한 step-by-step 가이드입니다.

---

## 0. 사전 준비물

| 항목 | 비고 |
|---|---|
| Windows 서버 (운영) 또는 macOS/Linux (개발) | XAMPP 호환 |
| XAMPP 8.x (Apache + MySQL + PHP 8.x) | https://www.apachefriends.org/ |
| Composer 2.x | phpunit 실행용 |
| Marketo 계정 + REST API Custom Service | Client ID / Secret / REST endpoint URL 필요 |
| Slack Incoming Webhook URL | 격리·crit 알림용 (선택) |

---

## 1. 코드 배포

```bash
# 1-1) XAMPP DocumentRoot 에 클론
cd C:\xampp\htdocs                          # Windows
# 또는 /Applications/XAMPP/xamppfiles/htdocs # macOS

git clone <repository_url> marketo-automation
cd marketo-automation

# 1-2) PHP 의존성 설치
composer install --no-dev   # 운영: --no-dev. 개발: 옵션 생략
```

---

## 2. 데이터베이스 셋업 (phpMyAdmin)

### 2-1. 빈 DB 생성
```sql
CREATE DATABASE marketo_automation
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

### 2-2. 메인 스키마 적용
phpMyAdmin → `marketo_automation` DB 선택 → SQL 탭 → 다음 파일 내용 붙여넣기 후 실행:
```
sql/schema.sql
```

### 2-3. 마이그레이션 적용 (순서 중요)
**아래 순서를 정확히 따라야 합니다.** 모두 phpMyAdmin SQL 탭에서 1개씩 실행:

| 순서 | 파일 | 도입 |
|---|---|---|
| 1 | `sql/migrations/approval.sql` | 결재 시스템 |
| 2 | `sql/migrations/defaults.sql` | 기본값 |
| 3 | `sql/migrations/bulk_import.sql` | Bulk Import 컬럼 |
| 4 | `sql/migrations/content_presets.sql` | 컨텐츠 프리셋 |
| 5 | `sql/migrations/delivery_tracking.sql` | 발송 결과 추적 |
| 6 | `sql/migrations/groups_marketo_ids.sql` | 그룹별 Marketo ID |
| 7 | `sql/migrations/run_id.sql` | run_id 추적 |
| 8 | `sql/migrations/screenshot.sql` | 스크린샷 첨부 |
| 9 | `sql/migrations/segment_id_index.sql` | 세그먼트 인덱스 |
| 10 | `sql/migrations/status_history.sql` | 상태 히스토리 |
| 11 | `sql/migrations/token_fields.sql` | My Token 필드 |
| 12 | `sql/migrations/vvip_suppression.sql` | VVIP 우선순위 |
| 13 | `sql/migrations/activity_next_token.sql` | Activity 폴링 |
| 14 | `sql/migrations/campaign_engagement.sql` | 발송 결과 통계 |
| 15 | `sql/migrations/marketo_api_calls.sql` | API 콜 카운터 |
| 16 | `sql/migrations/lead_send_cap.sql` | 리드별 cap (priority 차등) |

> ⚠️ **이미 일부 적용된 환경**: 기존 DB 가 있다면 `SHOW TABLES` 와 `DESCRIBE` 로 누락된 것만 적용. 중복 실행 시 일부 `ALTER TABLE` 가 fail 할 수 있으나 IF EXISTS 가드 덕에 데이터 손실은 없음.

---

## 3. config.php 작성

```bash
cp config/config.example.php config/config.php
```

`config/config.php` 를 열어 다음 값을 채웁니다:

### 3-1. 필수 — 타임존 (SEV1 RCA fix)
```php
date_default_timezone_set('Asia/Seoul');   // 절대 변경 금지
define('APP_INPUT_TIMEZONE', 'Asia/Seoul');
```
> ⚠️ SEV1 사고(2026-05-22) 의 1차 원인 — 누락 시 운영자 입력 시각이 9h 어긋남.

### 3-2. 필수 — DB 연결
```php
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_USER', 'root');
define('DB_PASS', '');                     // XAMPP 기본
define('DB_NAME', 'marketo_automation');
```

### 3-3. 필수 — 사내 DB (대상자 추출)
```php
define('INTERNAL_DB_HOST', '...');         // 읽기전용 백업 DB IP
define('INTERNAL_DB_USER', '...');
define('INTERNAL_DB_PASS', '...');
define('INTERNAL_DB_NAME', '...');
define('INTERNAL_DB_TABLE', 'users');
define('INTERNAL_DB_EMAIL_FIELD', 'email');
```
> ⚠️ **CONSTRAINT-01**: 사내 DB 는 SELECT only. UPDATE/INSERT/DELETE 자동 차단됨. 권한도 SELECT 만 부여 권장.

### 3-4. 필수 — Marketo REST
```php
define('MARKETO_CLIENT_ID', '...');
define('MARKETO_CLIENT_SECRET', '...');
define('MARKETO_REST_URL', 'https://XXX.mktorest.com/rest');       // /rest 로 끝남
define('MARKETO_IDENTITY_URL', 'https://XXX.mktorest.com/identity'); // /identity 로 끝남
```

### 3-5. 필수 — 발송 모드 (2026-05-26 추가)
```php
define('MARKETO_SEND_MODE', 'smart_campaign'); // 'smart_campaign' (권장) | 'email_program'
```
> 자세한 설명은 `config.example.php` 의 인라인 주석 참조. Marketo 인스턴스가 610 권한 차단 환경이면 `smart_campaign` 필수.

### 3-6. 권장 — Slack 알림
```php
define('SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/...');
```
> 비어 있으면 stdout fallback. needs_manual_review 격리/연속 실패/SEV1 자산 mismatch 등 'crit' 알림 보냄.

### 3-7. 권장 — 테스트 발송 수신자
```php
define('SEND_TEST_EMAIL_TO', 'tester@yourcompany.com,qa@yourcompany.com');
```

---

## 4. Apache 설정

### 4-1. `.htaccess` 확인
저장소에 포함됨. URL 라우팅 (`/api/...`, `/campaigns/...`) 처리.

### 4-2. Apache mod_rewrite 활성화 (XAMPP 기본 ON)
- `httpd.conf` 에서 `LoadModule rewrite_module ...` 주석 해제 확인
- `<Directory>` 블록에서 `AllowOverride All` 설정

### 4-3. 접속 테스트
- XAMPP Control Panel → Apache + MySQL Start
- 브라우저: `http://localhost/marketo-automation/`
- 캠페인 목록 페이지가 떠야 정상

---

## 5. Cron 등록 (백그라운드 자동화)

### 5-1. Windows Task Scheduler
다음 3개 작업을 등록:

| 작업명 | 명령어 | 주기 |
|---|---|---|
| Marketo 캠페인 스케줄러 | `php C:\xampp\htdocs\marketo-automation\cron\run_due_schedules.php` | 매 5분 |
| Bulk Import 폴링 | `php C:\xampp\htdocs\marketo-automation\cron\check_bulk_imports.php` | 매 30초 (또는 1분) |
| 발송 결과 Activity 폴링 | `php C:\xampp\htdocs\marketo-automation\cron\check_sent_activities.php` | 매 5분 |
| Lead cap 정리 | `php C:\xampp\htdocs\marketo-automation\cron\cleanup_lead_send_history.php` | 1일 1회 (새벽) |

> ⚠️ 모든 cron 은 *동시 실행 가드* (앱 DB 의 잠금 row) 가 내장. 같은 cron 이 2번 동시에 돌아도 안전.

### 5-2. Linux/macOS crontab
```cron
*/5  * * * * cd /path/to/marketo-automation && php cron/run_due_schedules.php >> logs/cron.log 2>&1
*/1  * * * * cd /path/to/marketo-automation && php cron/check_bulk_imports.php >> logs/cron.log 2>&1
*/5  * * * * cd /path/to/marketo-automation && php cron/check_sent_activities.php >> logs/cron.log 2>&1
0    3 * * * cd /path/to/marketo-automation && php cron/cleanup_lead_send_history.php >> logs/cron.log 2>&1
```

---

## 6. 검증 절차 (라이브 발송 전 필수)

### 6-1. phpunit 회귀 테스트
```bash
vendor/bin/phpunit
```
**기대치: 226 tests / 586 assertions OK** (2026-05-27 기준).
실패 시 적용 누락된 마이그레이션 또는 config 누락 가능성.

### 6-2. PHP syntax check
```bash
for f in $(find src api cron pages -name "*.php"); do php -l "$f"; done
```
모두 `No syntax errors` 출력.

### 6-3. Marketo 연결 테스트
브라우저: `http://localhost/marketo-automation/` 접속 후:
1. 우상단 "에셋 목록" 또는 캠페인 생성 페이지 → 이메일 에셋 드롭다운 → Marketo 가 돌려준 자산 목록이 표시되면 OK
2. 실패 시 `config.php` 의 4개 Marketo 키 + 토큰 캐시 (`config/marketo_token.cache`) 확인

### 6-4. 테스트 발송 1건
1. 신규 캠페인 → 'tester@...' 만 포함된 작은 세그먼트 → draft → 테스트 메일 자동 발송
2. 받은 메일에서:
   - 제목·프리헤더·이모지·보상URL 모두 의도값 표시 확인
   - **발송 시각 = 입력 시각 (KST 의도) 정확 확인** ← SEV1 회귀 가드 필수

### 6-5. 라이브 발송 SOP
`docs/SOP_LIVE_SEND.md` 의 6 키 체크리스트 모두 수동 통과 필수.

---

## 7. 트러블슈팅

| 증상 | 가능 원인 | 해결 |
|---|---|---|
| Marketo 토큰 401/602 반복 | TOKEN_CACHE_FILE 권한 | `config/marketo_token.cache` 삭제 후 재시도 |
| 발송 시각 9h 어긋남 | `date_default_timezone_set` 누락 | config.php 3-1 항목 확인 |
| Smart Campaign 셀렉트 빈값 | 이중 `/rest/` prefix 회귀 | `MarketoApiSpecGuardTest` 실행해 회귀 가드 통과 확인 |
| Bulk Import 무한 stuck | `/status.json` 변종 회귀 | `MarketoApiSpecGuardTest` 통과 확인 |
| Cron 실행해도 캠페인 schedule 안 됨 | scheduled_at 미래 | `campaigns.scheduled_at` 직접 확인 (= `send_time - 16h`) |
| needs_manual_review 격리 빈발 | asset name mismatch | `pages/isolation_queue.php` 에서 원인 확인 |

---

## 8. 다음 진입점

- 운영 SOP: `docs/SOP_LIVE_SEND.md`
- 본 PR 의 결정 사항 + 알려진 한계: `docs/HANDOFF.md`
- 시스템 전체 구조: `docs/architecture/OVERVIEW.md`
- 13단계 파이프라인: `docs/architecture/PIPELINE.md`
- 가드/재시도/관측 매트릭스: `docs/architecture/HARNESS.md`
