<?php
// src/helpers/kpi.php — KPI 대시보드 헬퍼 (ORCH Sprint 2)
declare(strict_types=1);

function _kpi_safe_select(string $sql, array $params = []): mixed
{
    try {
        $row = DB::one($sql, $params);
        if (!$row) return null;
        return array_values($row)[0] ?? null;
    } catch (Throwable $e) {
        return null;
    }
}

function kpi_sent_this_week(): array
{
    $since      = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
    $prev_since = (new DateTime('-14 days'))->format('Y-m-d H:i:s');
    $current = (int)(_kpi_safe_select(
        "SELECT COUNT(*) FROM campaigns WHERE status='sent' AND send_time >= ?",
        [$since]
    ) ?? 0);
    $prev = (int)(_kpi_safe_select(
        "SELECT COUNT(*) FROM campaigns WHERE status='sent' AND send_time >= ? AND send_time < ?",
        [$prev_since, $since]
    ) ?? 0);
    return ['value' => $current, 'prev' => $prev, 'unit' => '건'];
}

function kpi_avg_approval_minutes(): array
{
    $since      = (new DateTime('-30 days'))->format('Y-m-d H:i:s');
    $prev_since = (new DateTime('-60 days'))->format('Y-m-d H:i:s');
    $sql = "SELECT AVG(TIMESTAMPDIFF(MINUTE, h1.created_at, h2.created_at))
            FROM status_history h1
            JOIN status_history h2 ON h2.campaign_id = h1.campaign_id
            WHERE h1.to_status='awaiting_approval' AND h2.to_status='scheduling'
              AND h1.created_at >= ? AND h2.created_at > h1.created_at";
    $current = _kpi_safe_select($sql, [$since]);
    $prev_sql = str_replace('h1.created_at >= ?', 'h1.created_at >= ? AND h1.created_at < ?', $sql);
    $prev = _kpi_safe_select($prev_sql, [$prev_since, $since]);
    return [
        'value' => $current !== null ? round((float)$current, 1) : null,
        'prev'  => $prev    !== null ? round((float)$prev, 1)    : null,
        'unit'  => '분',
    ];
}

function kpi_avg_coverage_pct(): array
{
    $since      = (new DateTime('-30 days'))->format('Y-m-d H:i:s');
    $prev_since = (new DateTime('-60 days'))->format('Y-m-d H:i:s');
    $current = _kpi_safe_select(
        "SELECT AVG(CASE WHEN lead_count > 0 THEN sent_count * 100.0 / lead_count ELSE 0 END)
         FROM campaigns WHERE status='sent' AND send_time >= ?",
        [$since]
    );
    $prev = _kpi_safe_select(
        "SELECT AVG(CASE WHEN lead_count > 0 THEN sent_count * 100.0 / lead_count ELSE 0 END)
         FROM campaigns WHERE status='sent' AND send_time >= ? AND send_time < ?",
        [$prev_since, $since]
    );
    return [
        'value' => $current !== null ? round((float)$current, 1) : null,
        'prev'  => $prev    !== null ? round((float)$prev, 1)    : null,
        'unit'  => '%',
    ];
}

function kpi_needs_manual_review_count(): array
{
    $since      = (new DateTime('-30 days'))->format('Y-m-d H:i:s');
    $prev_since = (new DateTime('-60 days'))->format('Y-m-d H:i:s');
    $current = (int)(_kpi_safe_select(
        "SELECT COUNT(*) FROM status_history WHERE to_status='needs_manual_review' AND created_at >= ?",
        [$since]
    ) ?? 0);
    $prev = (int)(_kpi_safe_select(
        "SELECT COUNT(*) FROM status_history WHERE to_status='needs_manual_review' AND created_at >= ? AND created_at < ?",
        [$prev_since, $since]
    ) ?? 0);
    return ['value' => $current, 'prev' => $prev, 'unit' => '건'];
}

/** 트렌드 화살표 SVG (lower-is-better 인 경우 inverted=true) */
function kpi_trend_arrow_svg(?float $current, ?float $prev, bool $lower_is_better = false): string
{
    if ($current === null || $prev === null) return '';
    $delta = $current - $prev;
    if (abs($delta) < 0.01) {
        return '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-label="flat">'
             . '<path d="M2 7 L12 7" stroke="#888" stroke-width="2" stroke-linecap="round"/></svg>';
    }
    $up = $delta > 0;
    $color = $up
        ? ($lower_is_better ? '#dc3545' : '#198754')
        : ($lower_is_better ? '#198754' : '#dc3545');
    $path = $up
        ? '<path d="M7 2 L12 9 L2 9 Z" fill="' . $color . '"/>'
        : '<path d="M7 12 L12 5 L2 5 Z" fill="' . $color . '"/>';
    return '<svg width="14" height="14" viewBox="0 0 14 14" aria-label="' . ($up ? 'up' : 'down') . '">'
         . $path . '</svg>';
}
