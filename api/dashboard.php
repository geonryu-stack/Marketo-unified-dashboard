<?php
// api/dashboard.php
// 발송 결과 대시보드 데이터 endpoint. 마케터(비개발자)가 사용하는 /dashboard/results UI 가 호출.
//
// GET /api/dashboard/results        — 최근 N주 회차 카드 + 5대 KPI
// GET /api/dashboard/timeseries/{campaign_id} — 캠페인 일별 시계열
declare(strict_types=1);

api_handle(
    function (string $method, ?string $id, ?string $action, array $params): void {
        $id_param = $params['id'] ?? null;

        if ($action === 'results') {
            $weeks = isset($_GET['weeks']) ? max(1, min(12, (int)$_GET['weeks'])) : 4;
            $since = date('Y-m-d', strtotime("-{$weeks} weeks"));
            $rows  = DB::all(
                "SELECT id, name, segment_id, segment_name, send_time,
                        lead_count, sent_count, delivered_count, bounce_count,
                        open_count, click_count, unsubscribe_count,
                        status, poll_status, last_activity_at
                   FROM campaigns
                  WHERE DATE(send_time) >= ?
                    AND status IN ('scheduled', 'sent')
                  ORDER BY send_time DESC",
                [$since]
            );
            $results = array_map('compute_kpi', $rows);
            json_ok([
                'since'     => $since,
                'weeks'     => $weeks,
                'campaigns' => $results,
                'totals'    => aggregate_totals($results),
            ]);
        } elseif ($action === 'daily') {
            $weeks = isset($_GET['weeks']) ? max(1, min(12, (int)$_GET['weeks'])) : 4;
            $since = date('Y-m-d', strtotime("-{$weeks} weeks"));
            $rows = DB::all(
                "SELECT stat_date,
                        SUM(sent) as sent, SUM(delivered) as delivered,
                        SUM(bounce) as bounce, SUM(open) as `open`,
                        SUM(click) as click, SUM(unsubscribe) as unsubscribe
                   FROM campaign_daily_stats
                  WHERE stat_date >= ?
                  GROUP BY stat_date
                  ORDER BY stat_date ASC",
                [$since]
            );
            $pct = fn(int $n, int $d) => $d > 0 ? round(($n / $d) * 100, 1) : 0.0;
            $daily = array_map(function($r) use ($pct) {
                $sent = (int)$r['sent']; $del = (int)$r['delivered'];
                return $r + [
                    'delivery_rate' => $pct($del, $sent),
                    'open_rate'     => $pct((int)$r['open'], $del),
                    'ctr'           => $pct((int)$r['click'], $del),
                    'unsub_rate'    => $pct((int)$r['unsubscribe'], $del),
                ];
            }, $rows);
            json_ok(['since' => $since, 'rows' => $daily, 'totals' => aggregate_totals_from_rows($rows)]);

        } elseif ($action === 'weekly') {
            $weeks = isset($_GET['weeks']) ? max(1, min(24, (int)$_GET['weeks'])) : 8;
            $since = date('Y-m-d', strtotime("-{$weeks} weeks"));
            $rows = DB::all(
                "SELECT YEARWEEK(stat_date, 1) as yw,
                        MIN(stat_date) as week_start, MAX(stat_date) as week_end,
                        SUM(sent) as sent, SUM(delivered) as delivered,
                        SUM(bounce) as bounce, SUM(open) as `open`,
                        SUM(click) as click, SUM(unsubscribe) as unsubscribe
                   FROM campaign_daily_stats
                  WHERE stat_date >= ?
                  GROUP BY YEARWEEK(stat_date, 1)
                  ORDER BY yw ASC",
                [$since]
            );
            $pct = fn(int $n, int $d) => $d > 0 ? round(($n / $d) * 100, 1) : 0.0;
            $weekly = array_map(function($r) use ($pct) {
                $sent = (int)$r['sent']; $del = (int)$r['delivered'];
                $r['label'] = $r['week_start'] . ' ~ ' . $r['week_end'];
                return $r + [
                    'delivery_rate' => $pct($del, $sent),
                    'open_rate'     => $pct((int)$r['open'], $del),
                    'ctr'           => $pct((int)$r['click'], $del),
                    'unsub_rate'    => $pct((int)$r['unsubscribe'], $del),
                ];
            }, $rows);
            json_ok(['since' => $since, 'rows' => $weekly, 'totals' => aggregate_totals_from_rows($rows)]);

        } elseif ($action === 'monthly') {
            $months = isset($_GET['months']) ? max(1, min(12, (int)$_GET['months'])) : 6;
            $since = date('Y-m-d', strtotime("-{$months} months"));
            $rows = DB::all(
                "SELECT DATE_FORMAT(stat_date, '%Y-%m') as month,
                        SUM(sent) as sent, SUM(delivered) as delivered,
                        SUM(bounce) as bounce, SUM(open) as `open`,
                        SUM(click) as click, SUM(unsubscribe) as unsubscribe
                   FROM campaign_daily_stats
                  WHERE stat_date >= ?
                  GROUP BY DATE_FORMAT(stat_date, '%Y-%m')
                  ORDER BY month ASC",
                [$since]
            );
            $pct = fn(int $n, int $d) => $d > 0 ? round(($n / $d) * 100, 1) : 0.0;
            $monthly = array_map(function($r) use ($pct) {
                $sent = (int)$r['sent']; $del = (int)$r['delivered'];
                return $r + [
                    'delivery_rate' => $pct($del, $sent),
                    'open_rate'     => $pct((int)$r['open'], $del),
                    'ctr'           => $pct((int)$r['click'], $del),
                    'unsub_rate'    => $pct((int)$r['unsubscribe'], $del),
                ];
            }, $rows);
            json_ok(['since' => $since, 'rows' => $monthly, 'totals' => aggregate_totals_from_rows($rows)]);

        } elseif ($action === 'timeseries' && $id_param) {
            $row = DB::one('SELECT * FROM campaigns WHERE id=?', [$id_param]);
            if (!$row) json_err('캠페인을 찾을 수 없습니다.', 404);
            $series = DB::all(
                "SELECT stat_date, sent, delivered, bounce, open, click, unsubscribe
                   FROM campaign_daily_stats
                  WHERE campaign_id = ?
                  ORDER BY stat_date ASC",
                [$id_param]
            );
            json_ok([
                'campaign' => compute_kpi($row),
                'series'   => $series,
            ]);
        } else {
            json_err('Not Found', 404);
        }
    },
    [
        'allowed_methods' => ['GET'],
        'error_handler' => function (Throwable $e): void {
            if (defined('STDERR')) {
                @fwrite(STDERR, '[api/dashboard] ' . $e->getMessage() . "\n");
            }
            if (function_exists('error_log')) {
                error_log('[api/dashboard] ' . $e->getMessage());
            }
            json_err('대시보드 데이터 조회 중 오류가 발생했습니다. 잠시 후 다시 시도하세요.', 500);
        },
    ]
);

// ── 헬퍼: 5대 KPI 계산 (순수) ─────────────────────────────────
function compute_kpi(array $c): array
{
    $sent      = (int)($c['sent_count']      ?? 0);
    $delivered = (int)($c['delivered_count'] ?? 0);
    $bounce    = (int)($c['bounce_count']    ?? 0);
    $open      = (int)($c['open_count']      ?? 0);
    $click     = (int)($c['click_count']     ?? 0);
    $unsub     = (int)($c['unsubscribe_count'] ?? 0);

    $pct = fn(int $n, int $d) => $d > 0 ? round(($n / $d) * 100, 1) : 0.0;

    return [
        'id'             => $c['id'] ?? null,
        'name'           => $c['name'] ?? '',
        'segment_id'     => $c['segment_id'] ?? null,
        'segment_name'   => $c['segment_name'] ?? '',
        'send_time'      => $c['send_time'] ?? '',
        'status'         => $c['status'] ?? '',
        'poll_status'    => $c['poll_status'] ?? null,
        'last_activity_at' => $c['last_activity_at'] ?? null,
        'lead_count'     => (int)($c['lead_count'] ?? 0),
        'sent'           => $sent,
        'delivered'      => $delivered,
        'bounce'         => $bounce,
        'open'           => $open,
        'click'          => $click,
        'unsubscribe'    => $unsub,
        // 5대 KPI
        'delivery_rate'  => $pct($delivered, $sent),
        'open_rate'      => $pct($open,      $delivered),
        'ctr'            => $pct($click,     $delivered),
        'ctor'           => $pct($click,     $open),
        'unsub_rate'     => $pct($unsub,     $delivered),
    ];
}

function aggregate_totals(array $rows): array
{
    $sum = ['lead_count' => 0, 'sent' => 0, 'delivered' => 0, 'bounce' => 0,
            'open' => 0, 'click' => 0, 'unsubscribe' => 0];
    foreach ($rows as $r) {
        foreach (array_keys($sum) as $k) $sum[$k] += (int)$r[$k];
    }
    $pct = fn(int $n, int $d) => $d > 0 ? round(($n / $d) * 100, 1) : 0.0;
    return $sum + [
        'campaigns'     => count($rows),
        'delivery_rate' => $pct($sum['delivered'], $sum['sent']),
        'open_rate'     => $pct($sum['open'],      $sum['delivered']),
        'ctr'           => $pct($sum['click'],     $sum['delivered']),
        'ctor'          => $pct($sum['click'],     $sum['open']),
        'unsub_rate'    => $pct($sum['unsubscribe'], $sum['delivered']),
    ];
}

function aggregate_totals_from_rows(array $rows): array
{
    $sum = ['sent' => 0, 'delivered' => 0, 'bounce' => 0, 'open' => 0, 'click' => 0, 'unsubscribe' => 0];
    foreach ($rows as $r) {
        foreach (array_keys($sum) as $k) $sum[$k] += (int)$r[$k];
    }
    $pct = fn(int $n, int $d) => $d > 0 ? round(($n / $d) * 100, 1) : 0.0;
    return $sum + [
        'delivery_rate' => $pct($sum['delivered'], $sum['sent']),
        'open_rate'     => $pct($sum['open'],      $sum['delivered']),
        'ctr'           => $pct($sum['click'],     $sum['delivered']),
        'ctor'          => $pct($sum['click'],     $sum['open']),
        'unsub_rate'    => $pct($sum['unsubscribe'], $sum['delivered']),
    ];
}
