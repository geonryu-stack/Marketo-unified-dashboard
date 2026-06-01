<?php
// api/segments.php
declare(strict_types=1);
require_once __DIR__ . '/../src/Suppression.php';

$method = $_SERVER['REQUEST_METHOD'];
$params = $GLOBALS['route_params'] ?? [];
$id     = $params['id'] ?? null;
// index.php는 INFRA zone(동결)이라 새 라우트를 추가하지 않고 query param으로 분기.
//   GET /api/segments/{id}?action=cohort&limit=5 → cohort 추세 응답
$query_action = $_GET['action'] ?? null;

// sanitize_cap_int() は src/helpers/validation.php に統合済み (Phase 2)

try {
    // GET /api/segments — 목록
    if ($method === 'GET' && !$id) {
        $rows = DB::all('SELECT * FROM segments ORDER BY created_at DESC');
        json_ok($rows);
    }

    // GET /api/segments/{id}?action=cohort&limit=N — Sprint 2 DB (안정 API)
    // 같은 segment의 최근 sent 회차 추세(limit 기본 5, 최대 20).
    elseif ($method === 'GET' && $id && $query_action === 'cohort') {
        $exists = DB::one('SELECT id FROM segments WHERE id = ?', [$id]);
        if (!$exists) json_err('세그먼트를 찾을 수 없습니다.', 404);

        $limit = (int)($_GET['limit'] ?? 5);
        if ($limit < 1)  $limit = 5;
        if ($limit > 20) $limit = 20;

        // L1: LIMIT 파라미터화 (int-clamped 이지만 스타일 통일)
        $rows = DB::all(
            'SELECT id, name, send_time, lead_count, sent_count, delivered_count, bounce_count
               FROM campaigns
              WHERE segment_id = ? AND status = ?
              ORDER BY send_time DESC
              LIMIT ?',
            [$id, 'sent', $limit]
        );

        $campaigns = array_map('compute_cohort_stats', $rows);

        // 평균: 캠페인이 없으면 0.0
        $n = count($campaigns);
        if ($n === 0) {
            $avg_cov = 0.0;
            $avg_del = 0.0;
            $trend   = 'flat';
        } else {
            $sum_cov = 0.0;
            $sum_del = 0.0;
            foreach ($campaigns as $c) {
                $sum_cov += (float)$c['coverage_pct'];
                $sum_del += (float)$c['delivery_rate_pct'];
            }
            $avg_cov = round($sum_cov / $n, 2);
            $avg_del = round($sum_del / $n, 2);

            // 추세: 최신순 배열에서 최근 2건 평균 vs 그 이전 평균.
            // n<3이면 의미있는 비교 불가 → 'flat'.
            // delivery_rate_pct 기준 (전달율이 코호트 품질 1순위 지표).
            if ($n < 3) {
                $trend = 'flat';
            } else {
                $recent_avg = ($campaigns[0]['delivery_rate_pct'] + $campaigns[1]['delivery_rate_pct']) / 2;
                $prior_sum  = 0.0;
                for ($i = 2; $i < $n; $i++) {
                    $prior_sum += (float)$campaigns[$i]['delivery_rate_pct'];
                }
                $prior_avg = $prior_sum / ($n - 2);
                $diff      = $recent_avg - $prior_avg;
                if (abs($diff) <= 2.0)      $trend = 'flat';
                elseif ($diff > 0)          $trend = 'up';
                else                        $trend = 'down';
            }
        }

        json_ok([
            'campaigns'             => $campaigns,
            'avg_coverage_pct'      => $avg_cov,
            'avg_delivery_rate_pct' => $avg_del,
            'trend'                 => $trend,
        ]);
    }

    // GET /api/segments/{id}
    elseif ($method === 'GET' && $id) {
        $row = DB::one('SELECT * FROM segments WHERE id = ?', [$id]);
        if (!$row) json_err('세그먼트를 찾을 수 없습니다.', 404);
        json_ok($row);
    }

    // POST /api/segments — 생성
    elseif ($method === 'POST' && !$id) {
        $body    = parse_json_body();
        $filters = $body['filters'] ?? [];
        if (empty($filters)) {
            json_err('필터 조건이 없으면 전체 유저가 대상이 됩니다. 조건을 1개 이상 추가하세요.', 400);
        }
        $now  = now_str();
        $new_id = new_uuid();
        $suppresses_json = Suppression::sanitizeInput($body['suppresses_segment_ids'] ?? [], $new_id);
        $cap_per_day  = sanitize_cap_int($body['cap_per_day']  ?? null, 1);
        $cap_per_week = sanitize_cap_int($body['cap_per_week'] ?? null, 7);
        $cap_priority = sanitize_cap_int($body['cap_priority'] ?? null, 100);
        DB::exec(
            'INSERT INTO segments
             (id, name, description, filters, suppresses_segment_ids,
              marketo_program_id, marketo_audience_list_id, marketo_email_program_id,
              is_recurring, cap_per_day, cap_per_week, cap_priority,
              send_day_of_week, recurring_send_time,
              default_email_id, default_asset_name, default_reward_url,
              default_emoji, default_send_time, default_name_prefix,
              created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $new_id,
                $body['name'] ?? '',
                $body['description'] ?? '',
                json_encode($body['filters'] ?? []),
                $suppresses_json,
                $body['marketo_program_id'] ?? '',
                $body['marketo_audience_list_id'] ?? '',
                $body['marketo_email_program_id'] ?? '',
                (int)($body['is_recurring'] ?? 0),
                $cap_per_day, $cap_per_week, $cap_priority,
                (int)($body['send_day_of_week'] ?? 1),
                $body['recurring_send_time'] ?? '10:00',
                $body['default_email_id']    ?? '',
                $body['default_asset_name']  ?? '',
                $body['default_reward_url']  ?? '',
                $body['default_emoji']       ?? null,
                $body['default_send_time']   ?? '10:00',
                $body['default_name_prefix'] ?? '',
                $now, $now,
            ]
        );
        json_ok(DB::one('SELECT * FROM segments WHERE id = ?', [$new_id]));
    }

    // PUT /api/segments/{id} — 수정
    elseif ($method === 'PUT' && $id) {
        $existing = DB::one('SELECT * FROM segments WHERE id=?', [$id]);
        if (!$existing) json_err('세그먼트를 찾을 수 없습니다.', 404);

        $body    = parse_json_body();
        $filters = $body['filters'] ?? [];
        if (empty($filters)) {
            json_err('필터 조건이 없으면 전체 유저가 대상이 됩니다. 조건을 1개 이상 추가하세요.', 400);
        }
        // 미입력 시 기존 값 보존. 입력 시 자기참조·미존재 ID 검증.
        $suppresses_json = array_key_exists('suppresses_segment_ids', $body)
            ? Suppression::sanitizeInput($body['suppresses_segment_ids'], $id)
            : ($existing['suppresses_segment_ids'] ?? '[]');
        $now = now_str();
        $cap_per_day  = sanitize_cap_int($body['cap_per_day']  ?? null, (int)$existing['cap_per_day']);
        $cap_per_week = sanitize_cap_int($body['cap_per_week'] ?? null, (int)$existing['cap_per_week']);
        $cap_priority = sanitize_cap_int($body['cap_priority'] ?? null, (int)$existing['cap_priority']);
        DB::exec(
            'UPDATE segments SET
             name=?, description=?, filters=?, suppresses_segment_ids=?,
             marketo_program_id=?, marketo_audience_list_id=?, marketo_email_program_id=?,
             is_recurring=?, cap_per_day=?, cap_per_week=?, cap_priority=?,
             send_day_of_week=?, recurring_send_time=?,
             default_email_id=?, default_asset_name=?, default_reward_url=?,
             default_emoji=?, default_send_time=?, default_name_prefix=?,
             updated_at=?
             WHERE id=?',
            [
                $body['name']        ?? $existing['name'],
                $body['description'] ?? $existing['description'],
                json_encode($filters),
                $suppresses_json,
                $body['marketo_program_id']       ?? $existing['marketo_program_id'],
                $body['marketo_audience_list_id'] ?? $existing['marketo_audience_list_id'],
                $body['marketo_email_program_id'] ?? $existing['marketo_email_program_id'],
                isset($body['is_recurring'])      ? (int)$body['is_recurring']      : (int)$existing['is_recurring'],
                $cap_per_day, $cap_per_week, $cap_priority,
                isset($body['send_day_of_week'])  ? (int)$body['send_day_of_week']  : (int)$existing['send_day_of_week'],
                $body['recurring_send_time'] ?? $existing['recurring_send_time'],
                $body['default_email_id']    ?? $existing['default_email_id'],
                $body['default_asset_name']  ?? $existing['default_asset_name'],
                $body['default_reward_url']  ?? $existing['default_reward_url'],
                $body['default_emoji']       ?? $existing['default_emoji'],
                $body['default_send_time']   ?? $existing['default_send_time'],
                $body['default_name_prefix'] ?? $existing['default_name_prefix'],
                $now, $id,
            ]
        );
        json_ok(DB::one('SELECT * FROM segments WHERE id = ?', [$id]));
    }

    // DELETE /api/segments/{id}
    elseif ($method === 'DELETE' && $id) {
        DB::exec('DELETE FROM segments WHERE id = ?', [$id]);
        json_ok(null);
    }

    else {
        json_err('Not Found', 404);
    }
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}
