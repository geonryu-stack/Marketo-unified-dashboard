<?php
// config/config.example.php — 이 파일을 config.php로 복사 후 실제 값 입력

// ── SEV1 RCA(2026-05-22) 후속 — PHP timezone 강제 ─────────────
// 절대 누락 금지. 본 시스템은 send_time 을 'YYYY-MM-DDTHH:MM' (timezone 없음) 으로 받아
// strtotime → date 변환 후 Marketo 에 ISO8601 로 전달한다. 시스템 timezone 이
// 다르면 동일 입력값이 다른 UTC 시각으로 해석되어 *수신자에게 9시간 어긋난 시각* 발송됨.
// 운영 표준은 KST. 변경하지 말 것.
date_default_timezone_set('Asia/Seoul');

// ── 운영 표준 timezone (코드에서 참조) ─────────────────────────
// parse_send_time / Marketo runAt 변환에서 입력 datetime 의 의도된 timezone.
// 운영자가 '2026-05-21T10:00' 으로 입력하면 'KST 10:00 = UTC 01:00' 으로 변환된다.
define('APP_INPUT_TIMEZONE', 'Asia/Seoul');

// ── 앱 DB (XAMPP MySQL) ───────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'marketo_automation');

// ── 사내 DB (읽기전용) ─────────────────────────────────────────
define('INTERNAL_DB_HOST', '');
define('INTERNAL_DB_PORT', '3306');
define('INTERNAL_DB_USER', '');
define('INTERNAL_DB_PASS', '');
define('INTERNAL_DB_NAME', '');
define('INTERNAL_DB_TABLE', 'users');
define('INTERNAL_DB_EMAIL_FIELD', 'email');

// ── Marketo REST API ──────────────────────────────────────────
define('MARKETO_CLIENT_ID', '');
define('MARKETO_CLIENT_SECRET', '');
define('MARKETO_REST_URL', '');      // e.g. https://xxx.mktorest.com/rest
define('MARKETO_IDENTITY_URL', ''); // e.g. https://xxx.mktorest.com/identity

// ── Marketo 발송 모드 분기 ─────────────────────────────────────
// 본 시스템은 두 가지 Marketo 발송 경로를 지원하고, segments.marketo_email_program_id 컬럼은
// 모드에 따라 *다른 종류의 ID* 를 저장한다.
//
//   'smart_campaign' (기본, 권장):
//     - 컬럼에 Smart Campaign ID 저장 → POST /rest/v1/campaigns/{id}/schedule.json 으로 예약.
//     - inline tokens 전달 가능 (my.Title/Emoji/Preheader/RewardUrl 4종).
//     - 일부 Marketo 인스턴스가 Email Program POST 권한을 막아 610 'Access Denied' 를 돌려주므로
//       해당 환경에서는 본 모드 필수.
//     - 단점: Smart Campaign 에는 unapprove API 가 없어 cancel 시 운영자가 Marketo UI 에서
//       schedule 을 수동 제거해야 한다 (시스템은 awaiting_approval 로 되돌리기만 함).
//
//   'email_program':
//     - 컬럼에 Email Program ID 저장 → POST /rest/asset/v1/emailProgram/{id}/schedule.json.
//     - unapprove API 로 자동 cancel 가능.
//     - 610 권한 차단 인스턴스에서는 사용 불가.
//
// 변경 시 위험: 이미 segments 에 저장된 ID 가 다른 종류의 리소스 ID 로 해석되어 schedule API 가 깨짐.
// 운영 중 모드를 바꾸려면 모든 segments 의 marketo_email_program_id 를 새 모드의 ID 로 재입력 필수.
define('MARKETO_SEND_MODE', 'smart_campaign'); // 'smart_campaign' | 'email_program'

// ── 앱 설정 ───────────────────────────────────────────────────
define('SEND_TEST_EMAIL_TO', '');   // 쉼표 구분 e.g. a@b.com,c@d.com

// ── 우회 발송 대상자 (사내 DB 미사용 시 이 주소로 Static List 채움) ──
// 비워두면 INTERNAL_DB에서 추출. 라이브 테스트 시 실제 이메일 주소 입력.
// RTZ(수신자 현지 시각 발송)를 위해 'email|country' 형식으로 국가 지정 가능.
// e.g. 'a@b.com|South Korea,c@d.com|Japan'  (국가 생략 시 Marketo 계정 기본 timezone 사용)
define('INTERNAL_DB_BYPASS_LEADS', ''); // e.g. 'a@b.com|South Korea,c@d.com|South Korea'
define('APP_URL', 'http://localhost/marketo-automation');
define('APPROVAL_SECRET', 'CHANGE_ME_RANDOM_STRING_32_CHARS');

// ── Marketo Email Asset Library ──────────────────────────────
define('MARKETO_EMAIL_ASSET_LIBRARY_ID', 7321); // Email Asset Library 고정 Program ID (양의 정수)

// ── Bulk Import 분기 (대용량 발송 안정화) ─────────────────────
// 대상자가 BULK_THRESHOLD를 초과하면 REST 다건 호출 대신 Bulk Import API(CSV 1콜) 사용.
// 50K 단일 발송 시 좁은 시간 윈도우에 ~500 콜이 집중되는 것을 방지.
define('BULK_THRESHOLD', 10000);          // 이 이상이면 Bulk 경로
define('MARKETO_BULK_ENABLED', true);     // false면 임계값 초과해도 REST 경로 사용 (kill switch)

// CSV 사전검증 — Marketo Bulk Import 하드리미트 10MB. 9MB 마진(=10485760의 90%).
// 초과 시 RuntimeException → run_bulk_path가 'failed' 처리 + Slack 알림.
// 0 으로 두면 가드 비활성(롤백용 kill switch).
define('BULK_CSV_MAX_BYTES', 9 * 1024 * 1024);

// 사내 DB 연결·쿼리 타임아웃 (Fix 3 — DBA 개정안).
// CONNECT_TIMEOUT_SEC: TCP handshake/auth 단계 타임아웃. ATTR_TIMEOUT 매핑.
// QUERY_TIMEOUT_MS:    SELECT 쿼리 단위 timeout. 옵티마이저 힌트로 주입 →
//                      MySQL 5.7.8+ 에서 동작, MariaDB 는 주석으로 무시(엔진 무관 안전).
// 0 으로 두면 비활성(롤백용 kill switch).
define('INTERNAL_DB_CONNECT_TIMEOUT_SEC', 30);
define('INTERNAL_DB_QUERY_TIMEOUT_MS', 60000);

// Activity 폴링 cron 1주기당 최대 페이지 수 (Fix 2 — PR-2).
// Marketo Activity API 는 페이지당 최대 300건 → 500 페이지 ≈ 15만 activity.
// 60K 발송의 sent+delivered+bounce+open+click 합산 추정 150K 활동의 안전 마진.
// 캡 도달 시 마지막 nextPageToken 을 campaigns.activity_next_token 에 박제 →
// 다음 cron 주기가 이어받아 분할 폴링. 0 으로 두면 무제한(롤백용 kill switch).
define('MARKETO_ACTIVITY_MAX_PAGES_PER_CRON', 500);

// PR-4 (α) — 리스트 멤버 add/remove 청크 사이 페이싱 (마이크로초).
// 60K Bulk 경로에서 200 청크가 좁은 윈도우(100콜/20초)에 몰리는 것을 사전 분산.
// 150ms × 200 chunk ≈ 30s 추가 비용. 0 이면 비활성(롤백용 kill switch).
// 소규모 발송(< MARKETO_API_PACE_MIN_LEADS)은 영향 없음.
define('MARKETO_API_PACE_US',        150_000);
define('MARKETO_API_PACE_MIN_LEADS', 1000);

// PR-4 (δ) — Marketo API 일별 콜 카운터 활성화.
// false 면 marketo_api_calls 테이블 write 생략 (kill switch). DB 부하 우려 시 비활성.
define('MARKETO_API_USAGE_TRACKING', true);

// ── Marketo 토큰 캐시 파일 경로 ──────────────────────────────
define('TOKEN_CACHE_FILE', __DIR__ . '/marketo_token.cache');

// ── Sprint 0 INFRA 가드레일 ─────────────────────────────────
// true면 Marketo POST/DELETE 호출 no-op + 로그만 (실제 부수효과 없음).
// 본 sprint(S0)에서는 플래그만 정의. 실제 no-op 분기는 S1에 MKT zone에서 적용.
define('DRY_RUN_MODE', false); // true면 Marketo POST/DELETE 호출 no-op + 로그만

// ── Sprint 1 INFRA — 격리 알림용 ─────────────────────────────
// Slack incoming webhook URL. 빈 문자열이면 알림 비활성(개발 환경에서는 stdout로만 폴백).
// needs_manual_review 전이/연속 실패/Bulk 지연 등 HARNESS §C3 트리거가 사용.
define('SLACK_WEBHOOK_URL', '');

// ── Sprint 3 INFRA — 로그 포맷 토글 ──────────────────────────
// 'text' (기본): 기존 사람-친화 stdout 포맷.
// 'json'        : 한 줄당 1 JSON 객체(JSON Lines) — log shipper(Fluentd/Vector/Loki) 친화.
// job_logs DB 적재는 영향 받지 않음.
define('LOG_FORMAT', 'text');
