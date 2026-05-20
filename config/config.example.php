<?php
// config/config.example.php — 이 파일을 config.php로 복사 후 실제 값 입력

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
