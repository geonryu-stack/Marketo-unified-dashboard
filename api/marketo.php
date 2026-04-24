<?php
// api/marketo.php
declare(strict_types=1);
require_once __DIR__ . '/../src/MarketoAPI.php';

$resource = $GLOBALS['route_params']['resource'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];

try {
    if ($resource === 'emails' && $method === 'GET') {
        json_ok(MarketoAPI::getEmailList());
    } elseif ($resource === 'lists' && $method === 'GET') {
        json_ok(MarketoAPI::getStaticLists());
    } elseif ($resource === 'campaigns' && $method === 'GET') {
        json_ok(MarketoAPI::getSmartCampaigns());
    } else {
        json_err('Not Found', 404);
    }
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}
