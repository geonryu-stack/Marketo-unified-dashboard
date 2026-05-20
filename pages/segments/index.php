<?php
// pages/segments/index.php
$title   = '세그먼트';
$segments = DB::all('SELECT * FROM segments ORDER BY created_at DESC');
include __DIR__ . '/../layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2>세그먼트</h2>
  <a href="<?= APP_URL ?>/segments/new" class="btn btn-primary">+ 새 세그먼트</a>
</div>
<table class="table table-hover bg-white">
  <thead><tr>
    <th>이름</th><th>마지막 추출</th><th>대상자 수</th><th>반복 발송</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($segments as $seg): ?>
    <tr>
      <td><?= htmlspecialchars($seg['name']) ?></td>
      <td><?= $seg['last_extracted_at'] ? substr($seg['last_extracted_at'], 0, 16) : '-' ?></td>
      <td>
        <?php if ($seg['last_count'] !== null): ?>
          <?= number_format((int)$seg['last_count']) ?>명
          <br><small class="text-muted">스냅샷 1회차 — 변동량은 다음 sprint에서</small>
        <?php else: ?>
          <span class="text-muted">첫 추출 전</span>
        <?php endif; ?>
      </td>
      <td><?= $seg['is_recurring'] ? '<span class="badge bg-success">반복</span>' : '-' ?></td>
      <td class="text-end">
        <a href="<?= APP_URL ?>/segments/<?= $seg['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">편집</a>
        <button class="btn btn-sm btn-outline-danger ms-1"
                onclick="deleteSegment('<?= $seg['id'] ?>')">삭제</button>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($segments)): ?>
    <tr><td colspan="5" class="text-center text-muted py-4">세그먼트가 없습니다.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<script>
function deleteSegment(id) {
  if (!confirm('삭제하시겠습니까?')) return;
  fetch('<?= APP_URL ?>/api/segments/' + id, { method: 'DELETE' })
    .then(r => r.json())
    .then(d => { if (d.success) location.reload(); else alert(d.error); });
}
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
