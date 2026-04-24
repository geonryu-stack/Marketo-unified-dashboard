<?php
// pages/schedules/index.php
$title   = '발송 스케줄';
$scripts = ['schedules.js'];
include __DIR__ . '/../layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2>발송 스케줄</h2>
  <div class="d-flex gap-2 align-items-center">
    <button class="btn btn-outline-secondary btn-sm" id="btn-prev">◀ 이전 주</button>
    <span id="week-label" class="fw-bold"></span>
    <button class="btn btn-outline-secondary btn-sm" id="btn-next">다음 주 ▶</button>
  </div>
</div>
<div id="schedule-table-wrap"></div>
<script>
const APP_URL = '<?= APP_URL ?>';
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
