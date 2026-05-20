<?php
// pages/home.php
$title = '홈';
include __DIR__ . '/layout_header.php';

// ⑲ KPI 대시보드 (ORCH zone) — 라우터 변경 없이 home에 임베드
$kpi_sent     = kpi_sent_this_week();
$kpi_approval = kpi_avg_approval_minutes();
$kpi_coverage = kpi_avg_coverage_pct();
$kpi_review   = kpi_needs_manual_review_count();

function _kpi_render_card(string $label, array $kpi, string $hint, bool $lower_is_better = false): void
{
    $val   = $kpi['value'];
    $prev  = $kpi['prev'];
    $unit  = $kpi['unit'] ?? '';
    $arrow = kpi_trend_arrow_svg(
        $val === null ? null : (float)$val,
        $prev === null ? null : (float)$prev,
        $lower_is_better
    );
    $display = $val === null ? '<span class="text-muted">-</span>'
        : number_format(is_float($val) ? $val : (int)$val, is_float($val) ? 1 : 0)
          . '<span class="fs-6 text-muted ms-1">' . htmlspecialchars($unit) . '</span>';
    $prev_text = $prev === null ? '직전 기간 데이터 없음'
        : '직전 기간: ' . (is_float($prev) ? number_format((float)$prev, 1) : (int)$prev) . htmlspecialchars($unit);
    ?>
    <div class="col-md-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="text-muted text-uppercase small"><?= htmlspecialchars($label) ?></div>
          <div class="fs-3 fw-bold mt-1"><?= $display ?> <?= $arrow ?></div>
          <div class="text-muted small"><?= htmlspecialchars($prev_text) ?></div>
          <div class="text-muted small mt-2" style="font-size:11px"><?= htmlspecialchars($hint) ?></div>
        </div>
      </div>
    </div>
    <?php
}
?>
<h2>Marketo Automation Dashboard</h2>

<!-- KPI 카드 (4개) -->
<h5 class="text-muted mt-4 mb-2">운영 KPI</h5>
<div class="row g-3 mb-4">
  <?php _kpi_render_card('이번 주 발송수',       $kpi_sent,     '최근 7일, status=sent'); ?>
  <?php _kpi_render_card('평균 결재시간',         $kpi_approval, 'awaiting_approval → scheduling, 최근 30일', true); ?>
  <?php _kpi_render_card('평균 Coverage%',        $kpi_coverage, '최근 30일 sent 평균'); ?>
  <?php _kpi_render_card('needs_manual_review',   $kpi_review,   '최근 30일 격리 큐 진입', true); ?>
</div>

<!-- 네비게이션 -->
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
