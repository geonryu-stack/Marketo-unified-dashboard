<?php
// pages/campaigns/detail.php — $id는 router에서 주입
$c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
if (!$c) { header('Location: ' . APP_URL . '/campaigns'); exit; }
$title   = '캠페인: ' . htmlspecialchars($c['name']);
$scripts = ['campaign.js'];
include __DIR__ . '/../layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2><?= htmlspecialchars($c['name']) ?></h2>
  <span class="badge bg-<?= status_badge_class($c['status']) ?> fs-6"><?= status_label($c['status']) ?></span>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card"><div class="card-body">
      <dl class="row mb-0">
        <dt class="col-sm-5">세그먼트</dt><dd class="col-sm-7"><?= htmlspecialchars($c['segment_name']) ?></dd>
        <dt class="col-sm-5">예약 시각</dt><dd class="col-sm-7"><?= substr($c['scheduled_at'],0,16) ?></dd>
        <dt class="col-sm-5">발송 시각</dt><dd class="col-sm-7"><?= $c['send_time'] ?: '-' ?></dd>
        <dt class="col-sm-5">이모지</dt><dd class="col-sm-7"><?= htmlspecialchars($c['emoji'] ?? '-') ?></dd>
        <dt class="col-sm-5">대상자</dt><dd class="col-sm-7"><?= $c['lead_count'] > 0 ? number_format($c['lead_count']).'명' : '-' ?></dd>
      </dl>
    </div></div>
  </div>
  <?php if ($c['error_message']): ?>
  <div class="col-12">
    <div class="alert alert-danger"><?= htmlspecialchars($c['error_message']) ?></div>
  </div>
  <?php endif; ?>
</div>

<div class="d-flex gap-2 mb-4">
  <?php if (in_array($c['status'], ['draft','confirmed'])): ?>
    <button class="btn btn-success" onclick="campaign.confirm()">Phase 1 시작</button>
  <?php endif; ?>
  <?php if ($c['status'] === 'awaiting_approval'): ?>
    <button class="btn btn-primary" onclick="campaign.approve()">Phase 2 예약</button>
    <button class="btn btn-outline-danger" onclick="campaign.reject()">거절</button>
  <?php endif; ?>
  <?php if ($c['status'] === 'scheduled'): ?>
    <button class="btn btn-outline-danger" onclick="campaign.cancel()">예약 취소</button>
  <?php endif; ?>
  <?php if (in_array($c['status'], ['failed','awaiting_approval','scheduled'])): ?>
    <button class="btn btn-outline-secondary" onclick="campaign.resetToDraft()">초안으로 되돌리기</button>
  <?php endif; ?>
  <button class="btn btn-outline-danger ms-auto" onclick="campaign.deleteCampaign()">삭제</button>
</div>

<h5>실행 로그</h5>
<table class="table table-sm bg-white" id="log-table">
  <thead><tr><th>단계</th><th>상태</th><th>메시지</th><th>시각</th></tr></thead>
  <tbody id="log-body"></tbody>
</table>

<script>
const APP_URL     = '<?= APP_URL ?>';
const CAMPAIGN_ID = '<?= $c['id'] ?>';
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
