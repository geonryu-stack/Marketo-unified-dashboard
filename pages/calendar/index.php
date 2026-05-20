<?php
// pages/calendar/index.php — 발송 그룹별 캘린더 뷰 (Sprint 3 ORCH)
//
// URL: /calendar?from=YYYY-MM-DD
//   - from 기준 4주(28일) 캘린더를 그린다 (월~일 7열 x 4행).
//   - from 미지정 → 이번 주 월요일 자동.
//
// 데이터는 GET /api/calendar 에서 받아온다. 페이지 자체는 정적 HTML 골격만 렌더하고
// 그리드 채우기/네비/필터링은 클라이언트(JS)가 담당.
$title   = '캘린더';
$scripts = ['calendar.js'];

// 기본 시작일 = 이번 주 월요일 (요청에 from 있으면 우선)
$req_from = $_GET['from'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$req_from)) {
    $today_dow = (int)date('N');                                // 1(월)..7(일)
    $req_from  = date('Y-m-d', strtotime("-" . ($today_dow - 1) . " days"));
}
$from_date = $req_from;
$to_date   = date('Y-m-d', strtotime($from_date . ' +27 days'));

// from 이 월요일이 아니면 가장 가까운 직전 월요일로 보정 (캘린더 정렬 보존).
$dow = (int)date('N', strtotime($from_date));
if ($dow !== 1) {
    $from_date = date('Y-m-d', strtotime($from_date . ' -' . ($dow - 1) . ' days'));
    $to_date   = date('Y-m-d', strtotime($from_date . ' +27 days'));
}

include __DIR__ . '/../layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2>캘린더 <small class="text-muted fs-6" id="cal-range-label"></small></h2>
  <div class="d-flex gap-2 align-items-center">
    <button class="btn btn-outline-secondary btn-sm" id="cal-prev">◀ 이전 4주</button>
    <button class="btn btn-outline-secondary btn-sm" id="cal-today">이번 주</button>
    <button class="btn btn-outline-secondary btn-sm" id="cal-next">다음 4주 ▶</button>
  </div>
</div>

<div class="row g-3">
  <aside class="col-md-3">
    <div class="card sticky-top" style="top: 12px;">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>발송 그룹</strong>
        <small class="text-muted" id="cal-segments-count"></small>
      </div>
      <div class="card-body p-2" id="cal-sidebar">
        <div class="text-muted small">불러오는 중...</div>
      </div>
      <div class="card-footer small text-muted">
        클릭하면 해당 세그먼트의 캠페인만 표시합니다.
      </div>
    </div>
  </aside>
  <section class="col-md-9">
    <div class="card">
      <div class="card-body p-2">
        <div id="cal-grid" class="cal-grid">
          <div class="text-muted small p-3">불러오는 중...</div>
        </div>
        <div class="d-flex justify-content-end mt-2">
          <small class="text-muted">
            <span class="cal-legend cal-legend-scheduled"></span> 예약 완료
            <span class="cal-legend cal-legend-awaiting"></span> 결재 대기
            <span class="cal-legend cal-legend-sent"></span> 발송 완료
            <span class="cal-legend cal-legend-needs-review"></span> 수동 검토
            <span class="cal-legend cal-legend-other"></span> 기타
          </small>
        </div>
      </div>
    </div>
  </section>
</div>

<style>
/* 인라인 — 캘린더 전용 스타일. 캘린더 페이지가 유일한 소비자라 별도 CSS 파일을 만들지 않음. */
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
.cal-grid .cal-dow-header { font-weight: 600; text-align: center; padding: 4px 0; color: #555; font-size: 12px; }
.cal-grid .cal-cell {
  min-height: 96px; border: 1px solid #e5e7eb; border-radius: 4px; padding: 4px;
  background: #fafbfc; display: flex; flex-direction: column; gap: 2px;
}
.cal-grid .cal-cell.cal-today { background: #fff8e1; border-color: #f6c84c; }
.cal-grid .cal-cell.cal-past { background: #f3f4f6; }
.cal-grid .cal-cell .cal-date {
  font-size: 11px; color: #6b7280; display: flex; justify-content: space-between; align-items: center;
}
.cal-grid .cal-cell .cal-date strong { color: #111; }
.cal-grid .cal-cell .cal-event {
  font-size: 11px; padding: 2px 4px; border-radius: 3px; cursor: pointer; text-decoration: none;
  display: block; color: #111; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.cal-event-scheduled    { background: #d1fae5; border-left: 3px solid #10b981; }
.cal-event-awaiting     { background: #fef3c7; border-left: 3px solid #f59e0b; }
.cal-event-sent         { background: #dbeafe; border-left: 3px solid #3b82f6; }
.cal-event-needs-review { background: #fee2e2; border-left: 3px solid #ef4444; }
.cal-event-other        { background: #f3f4f6; border-left: 3px solid #9ca3af; }
.cal-legend { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-left: 12px; margin-right: 2px; vertical-align: middle; }
.cal-legend-scheduled    { background: #10b981; }
.cal-legend-awaiting     { background: #f59e0b; }
.cal-legend-sent         { background: #3b82f6; }
.cal-legend-needs-review { background: #ef4444; }
.cal-legend-other        { background: #9ca3af; }
.cal-sidebar-group       { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin: 6px 4px 2px; }
.cal-sidebar-item        {
  display: flex; justify-content: space-between; align-items: center;
  padding: 4px 6px; border-radius: 4px; cursor: pointer; font-size: 13px;
}
.cal-sidebar-item:hover  { background: #f3f4f6; }
.cal-sidebar-item.active { background: #dbeafe; font-weight: 600; }
.cal-sidebar-item .cal-count-badge {
  font-size: 11px; color: #6b7280; background: #e5e7eb; border-radius: 9999px; padding: 0 6px;
}
</style>

<script>
const APP_URL           = '<?= APP_URL ?>';
const CAL_INITIAL_FROM  = '<?= htmlspecialchars($from_date) ?>';
const CAL_INITIAL_TO    = '<?= htmlspecialchars($to_date) ?>';
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
