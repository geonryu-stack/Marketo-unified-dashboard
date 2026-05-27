<?php
// pages/segments/_suppression_section.php
// VVIP 우선순위 Suppression UI 컴포넌트. new.php / edit.php 가 공통 include.
//
// Host file 이 미리 정의해야 하는 변수:
//   $current_seg_id  string|null   편집 모드면 본인의 segments.id (자기 자신 제외용),
//                                  신규 생성이면 null
//   $current_supp    string|null   기존 suppresses_segment_ids JSON. 신규는 null.
require_once __DIR__ . '/../../src/Suppression.php';

$_supp_exclude  = $current_seg_id ?? null;
$_supp_selected = Suppression::decode($current_supp ?? null);
$_supp_options  = $_supp_exclude
    ? DB::all('SELECT id, name FROM segments WHERE id != ? ORDER BY name ASC', [$_supp_exclude])
    : DB::all('SELECT id, name FROM segments ORDER BY name ASC');
?>
<h5 class="mt-3">발송 우선순위 (Suppression)</h5>
<div class="mb-4">
  <label class="form-label small text-muted">
    이 세그먼트가 발송되는 날, 같은 날 발송 예정인 아래 세그먼트의 대상자에서
    본 세그먼트 모수를 자동 제외합니다.<br>
    <strong>VVIP의 경우 보통 Active A·B를 체크. FP/NP/타 그룹은 운영자 판단.</strong>
  </label>
  <select multiple class="form-select" name="suppresses_segment_ids" id="suppresses-segment-ids" size="6">
    <?php foreach ($_supp_options as $s): ?>
      <option value="<?= htmlspecialchars($s['id']) ?>"
        <?= in_array($s['id'], $_supp_selected, true) ? 'selected' : '' ?>>
        <?= htmlspecialchars($s['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <small class="text-muted">Ctrl(또는 ⌘) + 클릭으로 복수 선택. 비워두면 우선순위 없음(=일반 세그먼트).</small>
</div>
