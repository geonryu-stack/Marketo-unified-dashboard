<?php
// cron/cleanup_lead_send_history.php
//
// 리드별 cap 박제 테이블의 30일 초과 row 정리.
// cap 윈도우 최대 7일 가정 → 30일 보존이면 회고용 분석 마진 충분.
// 실행 주기: 1일 1회 (Windows Task Scheduler, 새벽 시간대 권장)
//
// 안전:
//   - DELETE 만 수행, 다른 테이블에 영향 없음.
//   - send_date < CURDATE() - 30 DAY 인 row 만 대상 → cap 계산 윈도우(현재~6일 전) 절대 침범 X.
declare(strict_types=1);

define('RUNNING_AS_CLI', true);
chdir(dirname(__DIR__));

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/SendCap.php';

job_log('lead_send_history 정리 cron 시작');

try {
    $affected = SendCap::purgeOlderThan(30);
    job_log("  완료: {$affected}건 삭제 (send_date < CURDATE() - 30 DAY)");
} catch (Throwable $e) {
    job_log('  실패: ' . $e->getMessage());
    error_log('[cleanup_lead_send_history] ' . $e->getMessage());
    exit(1);
}

job_log('lead_send_history 정리 cron 종료');
