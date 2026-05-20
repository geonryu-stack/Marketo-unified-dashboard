<?php
// api/marketo-url-parse.php — Post-S3 Operator Onboarding
// 운영자가 Marketo URL을 붙여넣으면 객체 종류 + ID + 어느 필드에 넣을지 자동 안내.
// POST { url: string } → { type, id, label, column }
declare(strict_types=1);
require_once __DIR__ . '/../src/MarketoUrl.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('POST 만 허용', 405);
}
$body = parse_json_body();
$url  = trim((string)($body['url'] ?? ''));
if ($url === '') json_err('url 필수', 400);

$parsed = MarketoUrl::parse($url);
if (!$parsed) {
    json_err('이 URL 에서 Marketo 객체 코드를 찾지 못했습니다. 예: https://app-XXX.marketo.com/#SC7610A1', 422);
}
$parsed['column'] = MarketoUrl::suggestedColumn($parsed['type']);
json_ok($parsed);
