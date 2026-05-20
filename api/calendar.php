<?php
// api/calendar.php
declare(strict_types=1);

/**
 * Sprint 3 ORCH — 발송 그룹별 캘린더 뷰 API.
 *
 *   GET /api/calendar?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * 응답:
 *   {
 *     success: true,
 *     data: {
 *       from: "YYYY-MM-DD",
 *       to:   "YYYY-MM-DD",
 *       campaigns: [
 *         { id, name, send_time, status, segment_id, segment_name,
 *           lead_count, is_recurring }
 *       ],
 *       segments: [
 *         { id, name, is_recurring, send_day_of_week, recurring_send_time }
 *       ]
 *     }
 *   }
 *
 * 설계 원칙:
 *  - SELECT 만 사용 (CONSTRAINT-01) — 페이지 렌더링 외 사이드이펙트 없음.
 *  - send_time 이 비어있는 캠페인은 캘린더 상에서 위치를 알 수 없으므로 제외.
 *  - segments 는 캘린더 좌측 사이드바에서 그룹핑 용도. is_recurring=1 그룹과
 *    is_recurring=0 그룹을 클라이언트가 분리하기 좋게 그대로 노출.
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    json_err('Method Not Allowed', 405);
}

$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to']   ?? ''));

// 입력 검증 — 캘린더 SQL 인젝션은 없지만, 파라미터를 정규식으로 강제해 잘못된 값이
// LIKE 패턴이나 BETWEEN 비교에 섞여 들어오지 않도록 1차 가드.
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    // 기본값: 이번 주 월요일 ~ 4주 후 일요일
    $today_dow = (int)date('N'); // 1(월) ~ 7(일)
    $monday    = date('Y-m-d', strtotime("-" . ($today_dow - 1) . " days"));
    $sunday28  = date('Y-m-d', strtotime($monday . ' +27 days'));
    $from = $from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : $monday;
    $to   = $to   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : $sunday28;
}

// 범위가 뒤집힌 경우 swap (사용자 실수 방지)
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

// send_time 컬럼은 VARCHAR(50) 이며 'YYYY-MM-DDTHH:MM' 또는 'YYYY-MM-DD HH:MM:SS' 형태를 모두
// 보일 수 있다. LEFT(send_time, 10) 으로 날짜 부분만 비교 → 두 표기 모두 안전하게 매칭.
$campaigns = DB::all(
    "SELECT c.id, c.name, c.send_time, c.status, c.segment_id, c.segment_name,
            c.lead_count, COALESCE(s.is_recurring, 0) AS is_recurring
       FROM campaigns c
       LEFT JOIN segments s ON s.id = c.segment_id
      WHERE c.send_time IS NOT NULL
        AND c.send_time <> ''
        AND LEFT(c.send_time, 10) BETWEEN ? AND ?
      ORDER BY c.send_time ASC, c.created_at ASC",
    [$from, $to]
);

$segments = DB::all(
    "SELECT id, name, is_recurring, send_day_of_week, recurring_send_time
       FROM segments
      ORDER BY is_recurring DESC, name ASC"
);

// 타입 정규화 — JSON으로 내보낼 때 lead_count/is_recurring/send_day_of_week 등이
// 문자열로 직렬화되면 클라이언트 비교가 어색해진다.
foreach ($campaigns as &$row) {
    $row['lead_count']   = (int)($row['lead_count']   ?? 0);
    $row['is_recurring'] = (int)($row['is_recurring'] ?? 0);
}
unset($row);
foreach ($segments as &$row) {
    $row['is_recurring']     = (int)($row['is_recurring']     ?? 0);
    $row['send_day_of_week'] = (int)($row['send_day_of_week'] ?? 1);
}
unset($row);

json_ok([
    'from'      => $from,
    'to'        => $to,
    'campaigns' => $campaigns,
    'segments'  => $segments,
]);
