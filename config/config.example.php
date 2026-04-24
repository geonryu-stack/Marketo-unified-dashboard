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
define('APP_URL', 'http://localhost/marketo-automation');
define('APPROVAL_SECRET', 'CHANGE_ME_RANDOM_STRING_32_CHARS');

// ── Marketo 토큰 캐시 파일 경로 ──────────────────────────────
define('TOKEN_CACHE_FILE', __DIR__ . '/marketo_token.cache');
