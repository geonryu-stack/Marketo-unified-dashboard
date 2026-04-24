<?php
// pages/segments/edit.php
// $id는 index.php 라우터에서 주입됨
$seg = DB::one('SELECT * FROM segments WHERE id = ?', [$id]);
if (!$seg) { header('Location: ' . APP_URL . '/segments'); exit; }

$seg['filters'] = json_decode($seg['filters'], true) ?? [];
$title          = '세그먼트 편집: ' . htmlspecialchars($seg['name']);
$field_defs     = get_field_defs();
$scripts        = ['segment-builder.js'];
include __DIR__ . '/../layout_header.php';
?>
<h2>세그먼트 편집</h2>
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

  <h5 class="mt-3">반복 발송</h5>
  <div class="row g-3 mb-4">
    <div class="col-auto">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="is_recurring" id="is_recurring"
               <?= $seg['is_recurring'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="is_recurring">반복 발송 활성화</label>
      </div>
    </div>
    <div class="col-md-2">
      <select class="form-select" name="send_day_of_week">
        <?php foreach (['일','월','화','수','목','금','토'] as $i => $d): ?>
          <option value="<?= $i ?>" <?= $seg['send_day_of_week'] == $i ? 'selected' : '' ?>><?= $d ?>요일</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <input type="time" class="form-control" name="recurring_send_time"
             value="<?= htmlspecialchars($seg['recurring_send_time'] ?? '10:00') ?>">
    </div>
  </div>

  <button type="submit" class="btn btn-primary">저장</button>
  <a href="<?= APP_URL ?>/segments" class="btn btn-outline-secondary ms-2">취소</a>
</form>

<script>
const APP_URL    = '<?= APP_URL ?>';
const FIELD_DEFS = <?= json_encode($field_defs, JSON_UNESCAPED_UNICODE) ?>;
const SEGMENT_ID = '<?= $seg['id'] ?>';
const INITIAL_FILTERS = <?= json_encode($seg['filters'], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
