<?php
// cron/check_sent_activities.php — Marketo Activity API로 캠페인 발송 결과 수집
// 실행 주기: 5분 (Windows Task Scheduler)
declare(strict_types=1);

define('RUNNING_AS_CLI', true);
chdir(dirname(__DIR__));

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Marketo/MarketoAPI.php';

job_log('Activity 폴링 cron 시작');

$due = DB::all(
    "SELECT c.*, s.marketo_audience_list_id
     FROM campaigns c
     JOIN segments s ON c.segment_id = s.id
     WHERE c.poll_status = 'polling' AND c.poll_next_at <= ?
     ORDER BY c.poll_next_at ASC
     LIMIT 10",
    [now_str()]
);

job_log(count($due) . '건 처리 시작');

foreach ($due as $c) {
    job_log("  [{$c['name']}]");
    try {
        $list_id = (int)$c['marketo_audience_list_id'];
        if (!$list_id) {
            job_log('    ⚠ audience_list_id 미설정 — 건너뜀');
            continue;
        }

        // send_time 기준 24시간 전부터 조회 (RTZ로 앞선 timezone 활동도 포함)
        $send_ts = strtotime(str_replace('T', ' ', $c['send_time'] ?? ''));
        if (!$send_ts) {
            job_log('    ⚠ send_time 파싱 실패 — 건너뜀');
            continue;
        }
        $since = date('Y-m-d\TH:i:s\Z', $send_ts - 86400);

        $acts = MarketoAPI::getEmailActivities($list_id, $since);

        $sent = $delivered = $bounce = 0;
        foreach ($acts as $a) {
            match((int)$a['activityTypeId']) {
                6      => $sent++,
                7      => $delivered++,
                11, 12 => $bounce++,
                default => null,
            };
        }

        $elapsed_min = (time() - strtotime($c['poll_started_at'] ?? '')) / 60;
        $lead_count  = (int)$c['lead_count'];
        $coverage    = $lead_count > 0 ? $sent / $lead_count : 0;
        $is_done     = ($elapsed_min >= 480) || ($coverage >= 0.95);

        $new_status = $is_done
            ? ($coverage >= 0.9 ? 'done' : 'timeout')
            : 'polling';

        $next_interval = match(true) {
            $elapsed_min < 60  => 5,
            $elapsed_min < 240 => 15,
            default            => 60,
        };
        $poll_next = $is_done ? null : date('Y-m-d H:i:s', time() + $next_interval * 60);
        $now       = now_str();

        DB::exec(
            "UPDATE campaigns SET
             sent_count=?, delivered_count=?, bounce_count=?,
             poll_status=?, poll_next_at=?, activity_polled_at=?, updated_at=?
             WHERE id=?",
            [$sent, $delivered, $bounce, $new_status, $poll_next, $now, $now, $c['id']]
        );

        job_log("    sent=$sent delivered=$delivered bounce=$bounce → $new_status");
    } catch (Throwable $e) {
        job_log('    ✗ ' . $e->getMessage());
    }
}

job_log('완료');
