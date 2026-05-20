<?php
// api/health.php
declare(strict_types=1);

/**
 * Sprint 2 INFRA — Healthcheck 엔드포인트 (STRATEGY.md §5 / HARNESS §C 관측 / §E 킬스위치).
 *
 * GET /api/health
 *
 * 응답 스키마 (예시는 STRATEGY 작업지시 참조):
 *   {
 *     "ok": true|false,
 *     "checks": {
 *       "app_db":                     {"ok": true,  "ms": 12},
 *       "internal_db":                {"ok": true,  "ms": 4,  "skipped": false},
 *       "marketo_token":              {"ok": true,  "expires_in_sec": 3300, "cached": true},
 *       "token_cache_file_writable":  {"ok": true},
 *       "screenshot_dir_writable":    {"ok": true,  "path": "data/screenshots"},
 *       "slack_webhook_configured":   {"ok": true|false}
 *     }
 *   }
 *
 * 설계 원칙:
 *   1) 각 체크는 5초 timeout — 실패해도 응답이 5초 안에 돌아오게 한다.
 *   2) marketo_token 은 **캐시된 토큰만** 확인. 신규 발급 시도 금지 (외부 호출 0).
 *   3) internal_db 가 미설정이면 skipped:true — critical 아님 (회사 환경에 따라 옵션).
 *   4) `ok` (최상위) 는 critical check 가 모두 ok 일 때만 true.
 *      - critical = app_db / token_cache_file_writable / screenshot_dir_writable / marketo_token / slack_webhook_configured
 *      - non-critical = internal_db (skipped 허용)
 *
 * 안정 API: 본 응답 스키마는 KPI 대시보드 / 모니터링 도구가 참고. 키 제거 금지 — 추가만.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET only'], JSON_UNESCAPED_UNICODE);
    return;
}

$checks = [];

// ── 1. app_db: SELECT 1 (12 → ms 측정) ─────────────────────────────
$checks['app_db'] = health_check_app_db();

// ── 2. internal_db: 설정되어 있으면 SELECT 1, 아니면 skipped ────────
$checks['internal_db'] = health_check_internal_db();

// ── 3. marketo_token: 캐시 파일만 검사 (신규 발급 금지) ─────────────
$checks['marketo_token'] = health_check_marketo_token();

// ── 4. token_cache_file_writable: 캐시 파일/디렉터리 쓰기 가능? ────
$checks['token_cache_file_writable'] = health_check_token_cache_writable();

// ── 5. screenshot_dir_writable: 스크린샷 저장 디렉터리 쓰기 가능? ──
$checks['screenshot_dir_writable'] = health_check_screenshot_dir();

// ── 6. slack_webhook_configured: webhook URL 설정 여부 ─────────────
$checks['slack_webhook_configured'] = health_check_slack_webhook();

// ── ok 종합 판정 ──────────────────────────────────────────────────
// internal_db.skipped == true 는 OK로 취급. 그 외 모든 critical check 가 ok 여야 함.
$critical_keys = [
    'app_db',
    'marketo_token',
    'token_cache_file_writable',
    'screenshot_dir_writable',
    'slack_webhook_configured',
];
$ok = true;
foreach ($critical_keys as $k) {
    if (empty($checks[$k]['ok'])) {
        $ok = false;
        break;
    }
}
// internal_db: ok=false AND skipped=false 인 경우만 전체 fail로 격상
if ($ok) {
    $idb = $checks['internal_db'];
    if (empty($idb['ok']) && empty($idb['skipped'])) {
        $ok = false;
    }
}

http_response_code($ok ? 200 : 503);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
return;

// ──────────────────────────────────────────────────────────────────
// 체크 함수들 — 모두 throw 금지. 실패시 ["ok"=>false, "error"=>"..."] 반환.
// ──────────────────────────────────────────────────────────────────

/**
 * app DB SELECT 1 — PDO 즉시 응답. timeout 은 PDO 내장(connect 단계는 OS TCP timeout 의존).
 * 호출자가 5초 안에 끝내야 하므로 명시적 PDO ATTR_TIMEOUT 은 설정하지 않음(MySQL 드라이버 미지원).
 * 대신 connect 단계가 막혀도 응답 자체는 다른 check 로 진행.
 */
function health_check_app_db(): array
{
    $t0 = microtime(true);
    try {
        $row = DB::one('SELECT 1 AS one');
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($row === null || (int)($row['one'] ?? 0) !== 1) {
            return ['ok' => false, 'ms' => $ms, 'error' => 'unexpected_result'];
        }
        return ['ok' => true, 'ms' => $ms];
    } catch (\Throwable $e) {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        return ['ok' => false, 'ms' => $ms, 'error' => substr($e->getMessage(), 0, 200)];
    }
}

/**
 * 사내 DB — 미설정(INTERNAL_DB_HOST 비어있음)이면 skipped:true 반환.
 * 설정되어 있으면 SELECT 1 시도. 실패는 ok:false (단, critical 아님).
 */
function health_check_internal_db(): array
{
    $host = defined('INTERNAL_DB_HOST') ? (string)INTERNAL_DB_HOST : '';
    if ($host === '') {
        return ['ok' => true, 'skipped' => true, 'reason' => 'INTERNAL_DB_HOST not configured'];
    }
    $t0 = microtime(true);
    try {
        // InternalDB::query 는 readonly enforce — 'SELECT 1' 통과.
        $rows = InternalDB::query('SELECT 1 AS one');
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $val = $rows[0]['one'] ?? null;
        if ((int)$val !== 1) {
            return ['ok' => false, 'ms' => $ms, 'skipped' => false, 'error' => 'unexpected_result'];
        }
        return ['ok' => true, 'ms' => $ms, 'skipped' => false];
    } catch (\Throwable $e) {
        $ms = (int)round((microtime(true) - $t0) * 1000);
        return ['ok' => false, 'ms' => $ms, 'skipped' => false, 'error' => substr($e->getMessage(), 0, 200)];
    }
}

/**
 * Marketo 토큰 — 캐시 파일만 검사. 재발급 절대 금지.
 *   - 캐시 파일 없음: ok=false, cached=false (cron이 아직 한 번도 안 돌았거나, 운영 시작 직전)
 *   - 캐시 파일 있음 + expires_at > now: ok=true, expires_in_sec=잔여시간
 *   - 캐시 파일 있음 + expires_at <= now: ok=false (만료) — 다음 호출에서 자동 갱신될 것이지만
 *     모니터링 입장에선 현시점 invalid
 */
function health_check_marketo_token(): array
{
    if (!defined('TOKEN_CACHE_FILE')) {
        return ['ok' => false, 'cached' => false, 'error' => 'TOKEN_CACHE_FILE constant not defined'];
    }
    $path = (string)TOKEN_CACHE_FILE;
    if (!file_exists($path)) {
        return ['ok' => false, 'cached' => false, 'error' => 'token cache file missing'];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'cached' => false, 'error' => 'token cache unreadable'];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['token']) || !isset($data['expires_at'])) {
        return ['ok' => false, 'cached' => true, 'error' => 'token cache malformed'];
    }
    $expires_at = (int)$data['expires_at'];
    $remaining = $expires_at - time();
    if ($remaining <= 0) {
        return ['ok' => false, 'cached' => true, 'expires_in_sec' => $remaining, 'error' => 'token expired'];
    }
    return ['ok' => true, 'cached' => true, 'expires_in_sec' => $remaining];
}

/**
 * 토큰 캐시 파일 (있으면 그 자체) 또는 부모 디렉터리의 쓰기 가능 여부.
 * 파일이 없으면 부모 디렉터리에 쓸 수 있는지로 대체 — 그래야 다음 getAccessToken() 호출이 캐시 갱신 가능.
 */
function health_check_token_cache_writable(): array
{
    if (!defined('TOKEN_CACHE_FILE')) {
        return ['ok' => false, 'error' => 'TOKEN_CACHE_FILE constant not defined'];
    }
    $path = (string)TOKEN_CACHE_FILE;
    if (file_exists($path)) {
        return ['ok' => is_writable($path)];
    }
    // 파일이 없으면 디렉터리 쓰기 가능성 확인.
    $dir = dirname($path);
    if ($dir === '' || $dir === '.') {
        return ['ok' => false, 'error' => 'cannot resolve directory'];
    }
    if (!is_dir($dir)) {
        return ['ok' => false, 'error' => 'parent dir missing: ' . $dir];
    }
    return ['ok' => is_writable($dir)];
}

/**
 * 스크린샷 저장 디렉터리 — helpers.php SCREENSHOT_STORAGE_SUBDIR 와 동일 위치.
 * 없으면 생성 시도. 생성 실패 시 ok=false.
 */
function health_check_screenshot_dir(): array
{
    // helpers.php 의 상수 — index.php require 시 로드됨.
    $subdir = defined('SCREENSHOT_STORAGE_SUBDIR') ? SCREENSHOT_STORAGE_SUBDIR : 'data/screenshots';
    $project_root = dirname(__DIR__);
    $abs = $project_root . '/' . $subdir;

    if (!is_dir($abs)) {
        if (!@mkdir($abs, 0775, true) && !is_dir($abs)) {
            return ['ok' => false, 'path' => $subdir, 'error' => 'cannot create directory'];
        }
    }
    return ['ok' => is_writable($abs), 'path' => $subdir];
}

/**
 * Slack webhook 설정 여부. 알림 throttle/큐 자체는 검사 안 함 — 설정값 존재만 확인.
 */
function health_check_slack_webhook(): array
{
    $url = defined('SLACK_WEBHOOK_URL') ? (string)SLACK_WEBHOOK_URL : '';
    return ['ok' => $url !== ''];
}
