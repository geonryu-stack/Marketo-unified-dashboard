<?php
// pages/segments/edit.php
// $id는 index.php 라우터에서 주입됨
$seg = DB::one('SELECT * FROM segments WHERE id = ?', [$id]);
if (!$seg) { header('Location: ' . APP_URL . '/segments'); exit; }

$seg['filters']    = json_decode($seg['filters'], true) ?? [];
$title             = '세그먼트 편집: ' . htmlspecialchars($seg['name']);
$all_defs          = get_field_defs();
$hidden_fields     = array_column(array_filter($all_defs, fn($d) => !empty($d['hidden'])), 'field');
$stripped_labels   = [];
foreach ($seg['filters'] as $f) {
    if (in_array($f['field'], $hidden_fields, true)) {
        $def = current(array_filter($all_defs, fn($d) => $d['field'] === $f['field']));
        $stripped_labels[] = $def['label'] ?? $f['field'];
    }
}
$seg['filters']    = array_values(array_filter($seg['filters'], fn($f) => !in_array($f['field'], $hidden_fields, true)));
$field_defs        = array_values(array_filter($all_defs, fn($d) => empty($d['hidden'])));
$scripts        = ['segment-builder.js'];
include __DIR__ . '/../layout_header.php';
?>
<h2>세그먼트 편집</h2>
<?php if (!empty($stripped_labels)): ?>
<div class="alert alert-warning mt-3">
  <strong>⚠ 사용 중단된 필터 조건이 자동으로 제거되었습니다.</strong><br>
  제거된 항목: <?= implode(', ', array_map('htmlspecialchars', $stripped_labels)) ?><br>
  아래에서 필터 조건을 확인하고, 필요한 조건을 다시 추가한 뒤 저장하세요.
  <strong>조건이 없으면 저장할 수 없습니다.</strong>
</div>
<?php endif; ?>
<form id="segment-form" class="mt-3">
  <div class="mb-3">
    <label class="form-label">이름 *</label>
    <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($seg['name']) ?>" required>
  </div>
  <div class="mb-3">
    <label class="form-label">설명</label>
    <input type="text" class="form-control" name="description" value="<?= htmlspecialchars($seg['description'] ?? '') ?>">
  </div>

  <h5 class="mt-4">필터 조건</h5>
  <div id="filter-rows"></div>
  <div class="d-flex gap-2 mt-2">
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addFilter()">+ 조건 추가</button>
    <button type="button" class="btn btn-outline-info btn-sm" onclick="previewCount()">대상자 미리보기</button>
    <span id="preview-count" class="align-self-center text-primary fw-bold"></span>
  </div>

  <h5 class="mt-4">Marketo 연결</h5>
  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <label class="form-label">Program ID</label>
      <input type="text" class="form-control" name="marketo_program_id"
             value="<?= htmlspecialchars($seg['marketo_program_id'] ?? '') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Audience List ID</label>
      <input type="text" class="form-control" name="marketo_audience_list_id"
             value="<?= htmlspecialchars($seg['marketo_audience_list_id'] ?? '') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Email Program ID</label>
      <input type="text" class="form-control" name="marketo_email_program_id"
             value="<?= htmlspecialchars($seg['marketo_email_program_id'] ?? '') ?>">
    </div>
  </div>

  <button type="submit" class="btn btn-primary mt-3">저장</button>
  <a href="<?= APP_URL ?>/segments" class="btn btn-outline-secondary ms-2">취소</a>
</form>

<script>
const APP_URL    = '<?= APP_URL ?>';
const FIELD_DEFS = <?= json_encode($field_defs, JSON_UNESCAPED_UNICODE) ?>;
const SEGMENT_ID = '<?= $seg['id'] ?>';
const INITIAL_FILTERS = <?= json_encode($seg['filters'], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
