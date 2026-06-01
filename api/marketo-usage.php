<?php
// api/marketo-usage.php
// PR-4 (δ) — Marketo API 일별 콜 분포 read-only 조회.
//
// GET /api/marketo-usage           → 오늘 분포
// GET /api/marketo-usage?date=YYYY-MM-DD → 특정 일자 분포
declare(strict_types=1);

require_once __DIR__ . '/../src/Marketo/MarketoApiUsage.php';

api_handle(function (string $method, ?string $id, ?string $action, array $params): void {
    $date = $_GET['date'] ?? null;
    if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_err('date 파라미터는 YYYY-MM-DD 형식이어야 합니다.', 400);
    }

    $summary = MarketoApiUsage::getDailySummary($date);
    $quota_pct = (int)round(min(100, ($summary['total'] / 50000) * 100));
    $summary['quota_pct']   = $quota_pct;
    $summary['quota_limit'] = 50000;
    $summary['near_limit']  = $quota_pct >= 80;
    json_ok($summary);
}, ['allowed_methods' => ['GET']]);
