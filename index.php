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


// ── 디스패치 ─────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base));
}
$uri = '/' . ltrim($uri, '/');

$router->dispatch($method, $uri);
