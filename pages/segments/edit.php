<?php
// pages/segments/edit.php
// $id는 index.php 라우터에서 주입됨
$seg = DB::one('SELECT * FROM segments WHERE id = ?', [$id]);
if (!$seg) { header('Location: ' . APP_URL . '/segments'); exit; }

$seg['filters'] = json_decode($seg['filters'], true) ?? [];
$title          = '세그먼트 편집: ' . htmlspecialchars($seg['name']);
$field_defs     = get_field_defs();
$scripts        = ['segment-builder.js'];
// VVIP suppression UI 컴포넌트가 사용할 컨텍스트
$current_seg_id = $seg['id'];
$current_supp   = $seg['suppresses_segment_ids'] ?? null;
// 리드별 cap UI 컴포넌트가 사용할 컨텍스트
$current_cap_per_day  = $seg['cap_per_day']  ?? 1;
$current_cap_per_week = $seg['cap_per_week'] ?? 7;
$current_cap_priority = $seg['cap_priority'] ?? 100;
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

  <!-- Marketo URL 자동 파싱 (Operator Onboarding) -->
  <div class="border rounded p-3 mb-3 bg-light">
    <label class="form-label mb-1">
      <strong>📋 Marketo URL 자동 입력</strong>
      <small class="text-muted fw-normal d-block mt-1">
        Marketo에서 객체 페이지를 열고 주소창의 URL을 그대로 붙여넣으면<br>
        ID 종류를 자동 식별해 아래 해당 필드에 채워줍니다.
      </small>
    </label>
    <div class="d-flex gap-2">
      <input type="url" class="form-control form-control-sm" id="marketo-url-input"
             placeholder="https://app-XXX.marketo.com/#SC7610A1">
      <button type="button" class="btn btn-outline-primary btn-sm" id="marketo-url-parse">자동 채움</button>
    </div>
    <div class="small mt-1" id="marketo-url-result"></div>
  </div>

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
      <input type="text" class="form-control" name="marketo_program_id" id="m-program-id"
             value="<?= htmlspecialchars($seg['marketo_program_id'] ?? '') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Audience List ID</label>
      <input type="text" class="form-control" name="marketo_audience_list_id" id="m-list-id"
             value="<?= htmlspecialchars($seg['marketo_audience_list_id'] ?? '') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Email Program <small class="text-muted fw-normal">(이름으로 선택)</small></label>
      <select class="form-select" name="marketo_email_program_id" id="m-ep-id"
              data-current-value="<?= htmlspecialchars($seg['marketo_email_program_id'] ?? '') ?>">
        <option value="">로딩 중...</option>
      </select>
    </div>
  </div>

  <?php include __DIR__ . '/_suppression_section.php'; ?>

  <?php include __DIR__ . '/_cap_section.php'; ?>

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
