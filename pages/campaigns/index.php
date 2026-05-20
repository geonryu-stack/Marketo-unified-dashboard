<?php
// pages/campaigns/index.php
$title  = '캠페인';
$filter = $_GET['filter'] ?? '';

// 지원 필터: awaiting_approval, needs_manual_review
$where = '';
if ($filter === 'awaiting_approval') {
    $where = "WHERE status='awaiting_approval'";
} elseif ($filter === 'needs_manual_review') {
    $where = "WHERE status='needs_manual_review'";
}

$campaigns    = DB::all("SELECT * FROM campaigns {$where} ORDER BY created_at DESC");
$pending      = (int)(DB::one("SELECT COUNT(*) AS n FROM campaigns WHERE status='awaiting_approval'")['n'] ?? 0);
$needs_review = (int)(DB::one("SELECT COUNT(*) AS n FROM campaigns WHERE status='needs_manual_review'")['n'] ?? 0);
include __DIR__ . '/../layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2>캠페인
    <?php if ($filter === 'awaiting_approval'): ?> <small class="text-muted">— 결재 대기만 보기</small>
    <?php elseif ($filter === 'needs_manual_review'): ?> <small class="text-muted">— 수동 검토 필요만 보기</small>
    <?php endif; ?>
  </h2>
  <a href="<?= APP_URL ?>/campaigns/new" class="btn btn-primary">+ 새 캠페인</a>
</div>

<?php if ($needs_review > 0): /* 빨강 배너 — 격리 큐 (최우선) */ ?>
<div class="alert alert-danger d-flex justify-content-between align-items-center mb-3">
  <span>⚠️ <strong>수동 검토 필요 캠페인 <?= $needs_review ?>건</strong> — Marketo EP 상태를 확인하고 'scheduled' 또는 'failed'로 해제하세요.</span>
  <?php if ($filter === 'needs_manual_review'): ?>
    <a href="<?= APP_URL ?>/campaigns" class="btn btn-sm btn-outline-light">전체 보기</a>
  <?php else: ?>
    <a href="<?= APP_URL ?>/campaigns?filter=needs_manual_review" class="btn btn-sm btn-light">수동 검토만 보기 →</a>
  <?php endif; ?>
</div>
<?php endif; ?>

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
    <?php
      $row_cls = '';
      if ($c['status'] === 'needs_manual_review')   $row_cls = ' class="table-danger"';
      elseif ($c['status'] === 'awaiting_approval') $row_cls = ' class="table-warning"';
    ?>
    <tr<?= $row_cls ?>>
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
