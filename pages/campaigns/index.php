<?php
// pages/campaigns/index.php
$title     = '캠페인';
$filter    = $_GET['filter'] ?? '';
$where     = $filter === 'awaiting_approval' ? "WHERE status='awaiting_approval'" : '';
$campaigns = DB::all("SELECT * FROM campaigns {$where} ORDER BY created_at DESC");
$pending   = (int)(DB::one("SELECT COUNT(*) AS n FROM campaigns WHERE status='awaiting_approval'")['n'] ?? 0);
include __DIR__ . '/../layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2>캠페인<?php if ($filter === 'awaiting_approval'): ?> <small class="text-muted">— 결재 대기만 보기</small><?php endif; ?></h2>
  <a href="<?= APP_URL ?>/campaigns/new" class="btn btn-primary">+ 새 캠페인</a>
</div>

<?php if ($pending > 0): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center mb-3">
  <span>🔔 <strong>결재 대기 캠페인 <?= $pending ?>건</strong> — 테스트 메일 확인 후 발송 승인을 결정하세요.</span>
  <?php if ($filter === 'awaiting_approval'): ?>
    <a href="<?= APP_URL ?>/campaigns" class="btn btn-sm btn-outline-dark">전체 보기</a>
  <?php else: ?>
    <a href="<?= APP_URL ?>/campaigns?filter=awaiting_approval" class="btn btn-sm btn-warning">결재 대기만 보기 →</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<table class="table table-hover bg-white">
  <thead><tr>
    <th>이름</th><th>세그먼트</th><th>발송 일시</th><th>대상자</th><th>상태</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($campaigns as $c): ?>
    <tr<?= $c['status'] === 'awaiting_approval' ? ' class="table-warning"' : '' ?>>
      <td><a href="<?= APP_URL ?>/campaigns/<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a></td>
      <td><?= htmlspecialchars($c['segment_name']) ?></td>
      <td><?= $c['send_time'] ? substr($c['send_time'], 0, 16) : '-' ?></td>
      <td><?= $c['lead_count'] > 0 ? number_format($c['lead_count']) . '명' : '-' ?></td>
      <td>
        <span class="badge bg-<?= status_badge_class($c['status']) ?>"><?= status_label($c['status']) ?></span>
      </td>
      <td class="text-end">
        <a href="<?= APP_URL ?>/campaigns/<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary">상세</a>
        <button onclick="duplicateFrom('<?= $c['id'] ?>')" class="btn btn-sm btn-outline-primary ms-1">다음 회차 복제</button>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($campaigns)): ?>
    <tr><td colspan="6" class="text-center text-muted py-4">캠페인이 없습니다.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<script>
const APP_URL = '<?= APP_URL ?>';
async function duplicateFrom(id) {
  if (!confirm('동일 설정으로 새 캠페인을 만드시겠습니까?')) return;
  const res  = await fetch(`${APP_URL}/api/campaigns/${id}/duplicate`, { method: 'POST' });
  const data = await res.json();
  if (data.success) location.href = `${APP_URL}/campaigns/${data.data.id}`;
  else alert('복사 실패: ' + data.error);
}
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
