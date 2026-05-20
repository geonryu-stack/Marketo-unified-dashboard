<?php
// index.php — 단일 진입점
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/src/Router.php';
require_once __DIR__ . '/src/DB.php';
require_once __DIR__ . '/src/InternalDB.php';
require_once __DIR__ . '/src/helpers.php';

$router = new Router();

// ── 페이지 라우트 ─────────────────────────────────────────────
$router->add('GET', '/', function ($p) {
    include __DIR__ . '/pages/home.php';
});
$router->add('GET', '/segments', function ($p) {
    include __DIR__ . '/pages/segments/index.php';
});
$router->add('GET', '/segments/new', function ($p) {
    include __DIR__ . '/pages/segments/new.php';
});
$router->add('GET', '/segments/{id}/edit', function ($p) {
    $id = $p['id'];
    include __DIR__ . '/pages/segments/edit.php';
});
$router->add('GET', '/campaigns', function ($p) {
    include __DIR__ . '/pages/campaigns/index.php';
});
$router->add('GET', '/campaigns/new', function ($p) {
    include __DIR__ . '/pages/campaigns/new.php';
});
$router->add('GET', '/campaigns/{id}', function ($p) {
    $id = $p['id'];
    include __DIR__ . '/pages/campaigns/detail.php';
});
$router->add('GET', '/campaigns/{id}/edit', function ($p) {
    $id = $p['id'];
    include __DIR__ . '/pages/campaigns/edit.php';
});
$router->add('GET', '/schedules', function ($p) {
    include __DIR__ . '/pages/schedules/index.php';
});

// ── API 라우트 ────────────────────────────────────────────────
$router->add('ANY', '/api/segments', function ($p) {
    require_once __DIR__ . '/api/segments.php';
});
$router->add('ANY', '/api/segments/{id}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/segments.php';
});
$router->add('ANY', '/api/campaigns', function ($p) {
    require_once __DIR__ . '/api/campaigns.php';
});
$router->add('ANY', '/api/campaigns/{id}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/campaigns.php';
});
$router->add('ANY', '/api/campaigns/{id}/{action}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/campaigns.php';
});
$router->add('ANY', '/api/schedules', function ($p) {
    require_once __DIR__ . '/api/schedules.php';
});
$router->add('ANY', '/api/schedules/{id}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/schedules.php';
});
$router->add('ANY', '/api/schedules/{id}/{action}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/schedules.php';
});
$router->add('ANY', '/api/internal-db/{action}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/internal-db.php';
});
$router->add('ANY', '/api/marketo/{resource}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/marketo.php';
});

// Sprint 2 INFRA — Healthcheck (GET-only).
// KPI 대시보드/Uptime 모니터링이 사용. 외부 호출 없이 5초 안에 응답.
$router->add('GET', '/api/health', function ($p) {
    require_once __DIR__ . '/api/health.php';
});

// Post-S3 운영자 피드백 #2 — 발송 그룹 프리셋
$router->add('GET', '/api/groups', function ($p) {
    require_once __DIR__ . '/api/groups.php';
});

// Post-S3 운영자 온보딩 — Marketo URL → ID 자동 파싱
$router->add('POST', '/api/marketo-url-parse', function ($p) {
    require_once __DIR__ . '/api/marketo-url-parse.php';
});

// Post-S3 운영자 피드백 #3 — 직전 회차 토큰 복사
$router->add('GET', '/api/segments/{id}/latest-tokens', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/segment-latest-tokens.php';
});

// ── Sprint 3 INFRA — 신규 라우트 등록 ─────────────────────────
// INFRA는 라우팅만 담당. 핸들러 파일(api/content-presets.php, api/calendar.php,
// pages/calendar/index.php)은 ASSET/ORCH 트랙이 만든다. 파일이 없을 때 라우트가
// 매칭되면 PHP 의 require_once/include 가 fatal error 를 던지므로, 트랙 머지
// 순서는 핸들러 → INFRA 라우터 순으로 합쳐도 되고, 라우터 → 핸들러 순으로
// 합쳐도 라우트가 호출되지 않는 한 안전하다 (현재 호출자는 신규 트랙들 뿐).
$router->add('ANY', '/api/content-presets', function ($p) {
    require_once __DIR__ . '/api/content-presets.php';
});
$router->add('ANY', '/api/content-presets/{id}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/content-presets.php';
});
$router->add('GET', '/api/calendar', function ($p) {
    require_once __DIR__ . '/api/calendar.php';
});
$router->add('GET', '/calendar', function ($p) {
    include __DIR__ . '/pages/calendar/index.php';
});
// 사내 DB 스키마 드리프트 — DB 트랙이 api/internal-db.php 안에서 action='schema-drift'
// 분기를 처리한다. 기존 ANY /api/internal-db/{action} catch-all 라우트가 이미
// schema-drift 도 매칭하므로 별도 라우트는 등록하지 않는다 (라우트 변경 금지 원칙).


// ── 디스패치 ─────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base));
}
$uri = '/' . ltrim($uri, '/');

$router->dispatch($method, $uri);
