<?php
// pages/segments/new.php
$title      = '새 세그먼트';
$field_defs = get_field_defs();
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

  <h5 class="mt-4 d-flex align-items-center gap-3">
    필터 조건
    <span class="form-check form-switch fs-6 fw-normal mb-0">
      <input class="form-check-input" type="checkbox" id="advanced-mode-toggle">
      <label class="form-check-label small text-muted" for="advanced-mode-toggle">
        고급 모드 (AND/OR/NOT 그룹)
      </label>
    </span>
  </h5>
  <div id="filter-rows"></div>
  <div class="d-flex gap-2 mt-2 align-items-center flex-wrap">
    <span id="flat-action-buttons">
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addFilter()">+ 조건 추가</button>
    </span>
    <button type="button" class="btn btn-outline-info btn-sm" onclick="previewCount()">대상자 미리보기</button>
    <button type="button" class="btn btn-outline-info btn-sm" onclick="previewSample()">표본 보기 (10건)</button>
    <div class="form-check ms-2">
      <input class="form-check-input" type="checkbox" id="consent-guard" checked>
      <label class="form-check-label small" for="consent-guard">마케팅 동의자만</label>
    </div>
    <span id="preview-count" class="align-self-center text-primary fw-bold"></span>
  </div>
  <div id="preview-sample"></div>

  <h5 class="mt-4">Marketo 연결</h5>
  <div class="row g-3 mb-2">
    <div class="col-md-6">
      <label class="form-label">발송 그룹 프리셋 <small class="text-muted fw-normal">(선택 시 아래 3개 ID 자동 채움)</small></label>
      <div class="d-flex gap-2">
        <select class="form-select" id="group-preset">
          <option value="">— 직접 입력 —</option>
        </select>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="group-preset-clear">초기화</button>
      </div>
    </div>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <label class="form-label">Program ID</label>
      <input type="text" class="form-control" name="marketo_program_id" id="m-program-id">
    </div>
    <div class="col-md-4">
      <label class="form-label">Audience List ID</label>
      <input type="text" class="form-control" name="marketo_audience_list_id" id="m-list-id">
    </div>
    <div class="col-md-4">
      <label class="form-label">Email Program ID</label>
      <input type="text" class="form-control" name="marketo_email_program_id" id="m-ep-id">
    </div>
  </div>

  <h5 class="mt-3">반복 발송</h5>
  <div class="row g-3 mb-4">
    <div class="col-auto">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="is_recurring" id="is_recurring">
        <label class="form-check-label" for="is_recurring">반복 발송 활성화</label>
      </div>
    </div>
    <div class="col-md-2">
      <select class="form-select" name="send_day_of_week">
        <?php foreach (['일','월','화','수','목','금','토'] as $i => $d): ?>
          <option value="<?= $i ?>"><?= $d ?>요일</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <input type="time" class="form-control" name="recurring_send_time" value="10:00">
    </div>
  </div>

  <button type="submit" class="btn btn-primary">저장</button>
  <a href="<?= APP_URL ?>/segments" class="btn btn-outline-secondary ms-2">취소</a>
</form>

<script>
const APP_URL  = '<?= APP_URL ?>';
const FIELD_DEFS = <?= json_encode($field_defs, JSON_UNESCAPED_UNICODE) ?>;
const SEGMENT_ID = null;
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
