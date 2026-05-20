<?php
// api/marketo.php
declare(strict_types=1);
require_once __DIR__ . '/../src/Marketo/MarketoAPI.php';

$resource = $GLOBALS['route_params']['resource'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];

try {
    if ($resource === 'emails' && $method === 'GET') {
        $program_id = (int)($_GET['program_id'] ?? 0);
        if ($program_id <= 0) json_err('program_id는 양의 정수여야 합니다.', 400);
        json_ok(MarketoAPI::getEmailsByProgram($program_id));
    } elseif ($resource === 'lists' && $method === 'GET') {
        json_ok(MarketoAPI::getStaticLists());
    } elseif ($resource === 'campaigns' && $method === 'GET') {
        json_ok(MarketoAPI::getSmartCampaigns());
    } elseif ($resource === 'email-programs' && $method === 'GET') {
        json_ok(MarketoAPI::getEmailPrograms());
    } elseif ($resource === 'programs' && $method === 'GET') {
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $name   = $_GET['name'] ?? '';
        json_ok($name ? MarketoAPI::getProgramByName($name) : MarketoAPI::getPrograms($offset));
    } elseif ($resource === 'program-tokens' && $method === 'GET') {
        $program_id = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
        if (!$program_id) json_err('program_id 필요', 400);
        json_ok(MarketoAPI::getProgramTokens($program_id));
    } else {
        json_err('Not Found', 404);
    }
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}
