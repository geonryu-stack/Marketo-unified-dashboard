<?php
// cron/sync_sent_campaigns.php — scheduled 캠페인이 send_time+30분 경과 시 sent 전환
//   + Activity 폴링(poll_status='polling') 시작.
// 실행 주기: 5~10분
declare(strict_types=1);

define('RUNNING_AS_CLI', true);
chdir(dirname(__DIR__));

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/helpers.php';

job_log('sent 전환 cron 시작');

// DB 레벨에서 직접 후보 필터링 + 한 번의 UPDATE로 전환 (왕복 N+1 제거).
// status='scheduled' AND send_time + 30m ≤ NOW(). send_time은 'YYYY-MM-DDTHH:MM' 또는
// 'YYYY-MM-DD HH:MM' 둘 다 허용되므로 REPLACE로 정규화 후 비교.
$now = now_str();

// 후보 미리 조회 (로그용)
$candidates = DB::all(
    "SELECT id, name, send_time FROM campaigns
      WHERE status = 'scheduled' AND send_time != ''
        AND (REPLACE(send_time, 'T', ' ') + INTERVAL 30 MINUTE) <= ?",
    [$now]
);

$transitioned = 0;
foreach ($candidates as $c) {
    // CAS 1건만 잡기 — 동시 실행 시 중복 전환 차단
    $affected = DB::exec(
        "UPDATE campaigns
            SET status='sent', poll_status='polling',
                poll_started_at=?, poll_next_at=?, updated_at=?
          WHERE id=? AND status='scheduled'",
        [$now, $now, $now, $c['id']]
    );
    if ($affected === 1) {
        job_log("  sent 전환 + polling 시작: {$c['name']} (send_time: {$c['send_time']})");
        $transitioned++;
    }
}

job_log("완료 — {$transitioned}건 전환");
