<?php
// pages/home.php
$title = '홈';
include __DIR__ . '/layout_header.php';
?>
<h2>Marketo Automation Dashboard</h2>
<div class="row mt-4 g-3">
  <div class="col-md-4">
    <a href="<?= APP_URL ?>/segments" class="card text-decoration-none text-dark">
      <div class="card-body text-center">
        <h5 class="card-title">세그먼트</h5>
        <p class="card-text text-muted">발송 대상자 필터 관리</p>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="<?= APP_URL ?>/campaigns" class="card text-decoration-none text-dark">
      <div class="card-body text-center">
        <h5 class="card-title">캠페인</h5>
        <p class="card-text text-muted">이메일 캠페인 실행 및 추적</p>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="<?= APP_URL ?>/schedules" class="card text-decoration-none text-dark">
      <div class="card-body text-center">
        <h5 class="card-title">발송 스케줄</h5>
        <p class="card-text text-muted">주간 발송 일정 관리</p>
      </div>
    </a>
  </div>
</div>
<?php include __DIR__ . '/layout_footer.php'; ?>
