<?php
// scripts/dev-router.php
// PHP 내장 서버 전용 라우터. config/config.php의 APP_URL
// 'http://localhost:8080/marketo-send-automation' 와 동일한 prefix를 흉내내기 위해
// PHP 내장 서버를 docroot=부모 디렉터리로 띄우고 이 파일을 router 로 지정한다.
//
// 사용:
//   php -S localhost:8080 -t /Users/geonwoo \
//       /Users/geonwoo/marketo-send-automation/scripts/dev-router.php
//
// 그러면 http://localhost:8080/marketo-send-automation/ 접속이 운영 환경과 동일하게 동작.
declare(strict_types=1);

$project_root = realpath(__DIR__ . '/..');
$uri          = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// /marketo-send-automation prefix 제거 후 프로젝트 내부 경로로 매핑
$prefix = '/marketo-send-automation';
if (str_starts_with($uri, $prefix . '/') || $uri === $prefix) {
    $internal = substr($uri, strlen($prefix));
    if ($internal === '' || $internal === '/') {
        $internal = '/index.php';
    }
} else {
    // /marketo-send-automation prefix 없는 요청은 404 또는 안내
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $url = rtrim('http://' . $_SERVER['HTTP_HOST'], '/') . $prefix . '/';
    echo "<h1>404 — Wrong path</h1><p>Try <a href=\"{$url}\">{$url}</a></p>";
    return;
}

// 정적 파일이면 PHP 내장 서버에 위임 (false 반환)
$candidate = $project_root . $internal;
if (is_file($candidate) && !str_ends_with($candidate, '.php')) {
    return false;
}

// PHP 파일이면 index.php 라우터로 위임
$_SERVER['SCRIPT_NAME'] = $prefix . '/index.php';
$_SERVER['PHP_SELF']    = $prefix . '/index.php';
require $project_root . '/index.php';
