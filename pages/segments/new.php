<?php
// pages/segments/new.php
$title      = '새 세그먼트';
$field_defs = array_values(array_filter(get_field_defs(), fn($d) => empty($d['hidden'])));
$scripts    = ['segment-builder.js'];
include __DIR__ . '/../layout_header.php';
?>
<h2>새 세그먼트</h2>
<form id="segment-form" class="mt-3">
  <div class="mb-3">
    <label class="form-label">이름 *</label>
    <input type="text" class="form-control" name="name" required>
  </div>
  <div class="mb-3">
    <label class="form-label">설명</label>
    <input type="text" class="form-control" name="description">
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
      <input type="text" class="form-control" name="marketo_program_id">
    </div>
    <div class="col-md-4">
      <label class="form-label">Audience List ID</label>
      <input type="text" class="form-control" name="marketo_audience_list_id">
    </div>
    <div class="col-md-4">
      <label class="form-label">Email Program ID</label>
      <input type="text" class="form-control" name="marketo_email_program_id">
    </div>
  </div>

  <button type="submit" class="btn btn-primary mt-3">저장</button>
  <a href="<?= APP_URL ?>/segments" class="btn btn-outline-secondary ms-2">취소</a>
</form>

<script>
const APP_URL  = '<?= APP_URL ?>';
const FIELD_DEFS = <?= json_encode($field_defs, JSON_UNESCAPED_UNICODE) ?>;
const SEGMENT_ID = null;
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
