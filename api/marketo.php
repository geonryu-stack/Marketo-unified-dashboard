<?php
// api/marketo.php
declare(strict_types=1);
require_once __DIR__ . '/../src/Marketo/MarketoAPI.php';

api_handle(function (string $method, ?string $id, ?string $action, array $params): void {
    $resource = $params['resource'] ?? '';

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
    } elseif ($resource === 'ep-status' && $method === 'GET') {
        $ep_id = isset($_GET['ep_id']) ? (int)$_GET['ep_id'] : 0;
        if ($ep_id < 1) json_err('ep_id는 1 이상의 정수여야 합니다.', 400);
        json_ok(MarketoAPI::getEmailProgramSnapshot($ep_id));
    } else {
        json_err('Not Found', 404);
    }
});
