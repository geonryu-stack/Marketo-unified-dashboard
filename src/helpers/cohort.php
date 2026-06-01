<?php
// src/helpers/cohort.php — 코호트 통계 계산 (C-COHORT)
declare(strict_types=1);

/**
 * 캠페인 한 행에서 coverage_pct / delivery_rate_pct 를 계산해 추가한다.
 *
 * @param array $row campaigns 한 행. 최소 lead_count, sent_count, delivered_count, bounce_count 포함.
 * @return array     입력 키 모두 보존 + coverage_pct / delivery_rate_pct 추가.
 */
function compute_cohort_stats(array $row): array
{
    $lead      = (int)($row['lead_count']      ?? 0);
    $sent      = (int)($row['sent_count']      ?? 0);
    $delivered = (int)($row['delivered_count'] ?? 0);

    $coverage      = $lead > 0 ? round(($sent      / $lead) * 100, 2) : 0.0;
    $delivery_rate = $sent > 0 ? round(($delivered / $sent) * 100, 2) : 0.0;

    $row['coverage_pct']      = $coverage;
    $row['delivery_rate_pct'] = $delivery_rate;
    return $row;
}
