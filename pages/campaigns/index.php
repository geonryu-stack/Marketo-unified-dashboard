<?php
// pages/campaigns/index.php
$title     = '캠페인';
$campaigns = DB::all('SELECT * FROM campaigns ORDER BY created_at DESC');
include __DIR__ . '/../layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2>캠페인</h2>
  <a href="<?= APP_URL ?>/campaigns/new" class="btn btn-primary">+ 새 캠페인</a>
</div>
<table class="table table-hover bg-white">
  <thead><tr>
    <th>이름</th><th>세그먼트</th><th>예약 시각</th><th>대상자</th><th>상태</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($campaigns as $c): ?>
    <tr>
      <td><a href="<?= APP_URL ?>/campaigns/<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a></td>
      <td><?= htmlspecialchars($c['segment_name']) ?></td>
      <td><?= substr($c['scheduled_at'], 0, 16) ?></td>
      <td><?= $c['lead_count'] > 0 ? number_format($c['lead_count']) . '명' : '-' ?></td>
      <td><span class="badge bg-<?= status_badge_class($c['status']) ?>"><?= status_label($c['status']) ?></span></td>
      <td class="text-end">
        <a href="<?= APP_URL ?>/campaigns/<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary">상세</a>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($campaigns)): ?>
    <tr><td colspan="6" class="text-center text-muted py-4">캠페인이 없습니다.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?php include __DIR__ . '/../layout_footer.php'; ?>
