<?php
// api/internal-db.php
declare(strict_types=1);
require_once __DIR__ . '/../src/InternalDB.php';

$action = $GLOBALS['route_params']['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// GET /api/internal-db/fields
if ($action === 'fields' && $method === 'GET') {
    json_ok(get_field_defs());
}

// POST /api/internal-db/preview
elseif ($action === 'preview' && $method === 'POST') {
    try {
        $body    = parse_json_body();
        $filters = $body['filters'] ?? [];
        ['sql' => $where, 'params' => $params] = build_where_clause($filters, get_field_defs());

        $table   = INTERNAL_DB_TABLE;
        $sql     = "SELECT COUNT(*) AS cnt FROM `$table` WHERE $where";
        assert_readonly($sql);

        $rows  = InternalDB::query($sql, $params);
        $count = (int)($rows[0]['cnt'] ?? 0);
        json_ok(['count' => $count]);
    } catch (Throwable $e) {
        json_err($e->getMessage());
    }
}

else {
    json_err('Not Found', 404);
}
