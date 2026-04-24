<?php
// cron/check_sent_campaigns.php — scheduled → sent 자동 전환 (발송시각 +30분 버퍼)
// 실행: php C:\xampp\htdocs\marketo-automation\cron\check_sent_campaigns.php
declare(strict_types=1);

define('RUNNING_AS_CLI', true);

chdir(dirname(__DIR__));

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/helpers.php';

function cron_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

cron_log('check_sent 시작');

$candidates = DB::all(
    "SELECT id, name, scheduled_at, send_time FROM campaigns
     WHERE status = 'scheduled'
     ORDER BY scheduled_at ASC LIMIT 20",
    []
);

if (empty($candidates)) {
    cron_log('확인 대상 없음');
    exit(0);
}

$now    = new DateTime();
$marked = 0;

foreach ($candidates as $c) {
    // scheduled_at 날짜 + send_time 조합 → 실제 발송 시각
    $date_part = date('Y-m-d', strtotime($c['scheduled_at']));
    $time_part = ($c['send_time'] !== '' && $c['send_time'] !== null)
        ? $c['send_time'] . ':00'
        : date('H:i:s', strtotime($c['scheduled_at']));

    $send_at = new DateTime("{$date_part} {$time_part}");
    $send_at->modify('+30 minutes');

    if ($now < $send_at) continue;

    // 상태 재확인 (동시 실행 방지)
    $latest = DB::one('SELECT status FROM campaigns WHERE id=?', [$c['id']]);
    if (!$latest || $latest['status'] !== 'scheduled') continue;

    DB::exec(
        'UPDATE campaigns SET status=?, updated_at=? WHERE id=?',
        ['sent', now_str(), $c['id']]
    );
    cron_log("발송 완료 처리: {$c['name']} (예약: {$date_part} {$time_part})");
    $marked++;
}

cron_log("완료 — {$marked}개 처리");
