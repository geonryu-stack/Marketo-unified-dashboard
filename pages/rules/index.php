<?php
// pages/rules/index.php — 발송 Rule (전체 세그먼트 cap/suppression 일괄 관리)
$title   = '발송 Rule';
$scripts = ['rules.js'];

$segments = DB::all('SELECT id, name, cap_per_day, cap_per_week, cap_priority, suppresses_segment_ids FROM segments ORDER BY cap_priority DESC, name ASC');

// 세그먼트 ID→이름 맵 (suppress 대상 이름 표시용)
$seg_name_map = [];
foreach ($segments as $s) {
    $seg_name_map[$s['id']] = $s['name'];
}

include __DIR__ . '/../layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2 class="mb-0">발송 Rule</h2>
  <button type="button" class="btn btn-primary" id="btn-save-all" disabled>일괄 저장</button>
</div>

<div class="alert alert-secondary small mb-3">
  <strong>우선순위 권장값:</strong> VVIP = 200, 일반 = 100, 마케팅성 = 50.
  숫자가 클수록 우선 발송되며, 우선순위가 같거나 높은 캠페인이 점유한 수신자는 낮은 세그먼트에서 자동 제외됩니다.
</div>

<div class="table-responsive">
  <table class="table table-bordered table-hover align-middle" id="rules-table">
    <thead class="table-light">
      <tr>
        <th>세그먼트명</th>
        <th style="width:110px">우선순위</th>
        <th style="width:110px">일 cap</th>
        <th style="width:110px">주 cap</th>
        <th style="width:280px">Suppress 대상</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($segments as $seg):
        $supp_ids = json_decode($seg['suppresses_segment_ids'] ?? '[]', true) ?: [];
      ?>
      <tr data-id="<?= htmlspecialchars($seg['id']) ?>"
          data-original-cap-day="<?= (int)($seg['cap_per_day'] ?? 1) ?>"
          data-original-cap-week="<?= (int)($seg['cap_per_week'] ?? 7) ?>"
          data-original-priority="<?= (int)($seg['cap_priority'] ?? 100) ?>"
          data-original-suppress="<?= htmlspecialchars(json_encode($supp_ids)) ?>">
        <td class="fw-semibold"><?= htmlspecialchars($seg['name']) ?></td>
        <td>
          <input type="number" class="form-control form-control-sm rule-input" name="cap_priority"
                 min="0" max="9999" step="1" value="<?= (int)($seg['cap_priority'] ?? 100) ?>">
        </td>
        <td>
          <input type="number" class="form-control form-control-sm rule-input" name="cap_per_day"
                 min="0" max="9999" step="1" value="<?= (int)($seg['cap_per_day'] ?? 1) ?>">
        </td>
        <td>
          <input type="number" class="form-control form-control-sm rule-input" name="cap_per_week"
                 min="0" max="9999" step="1" value="<?= (int)($seg['cap_per_week'] ?? 7) ?>">
        </td>
        <td>
          <div class="dropdown suppress-dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle w-100 text-start" type="button"
                    data-bs-toggle="dropdown" data-bs-auto-close="outside">
              <?php
              $badge_html = '';
              foreach ($supp_ids as $sid) {
                  $sname = $seg_name_map[$sid] ?? '(삭제됨)';
                  $badge_html .= '<span class="badge bg-info text-dark me-1">' . htmlspecialchars($sname) . '</span>';
              }
              echo $badge_html ?: '<span class="text-muted">없음</span>';
              ?>
            </button>
            <ul class="dropdown-menu p-2" style="min-width:240px; max-height:260px; overflow-y:auto;">
              <?php foreach ($segments as $opt):
                if ($opt['id'] === $seg['id']) continue; // 자기 자신 제외
              ?>
              <li>
                <label class="dropdown-item d-flex align-items-center gap-2 py-1 px-2">
                  <input type="checkbox" class="form-check-input suppress-check" value="<?= htmlspecialchars($opt['id']) ?>"
                    <?= in_array($opt['id'], $supp_ids, true) ? 'checked' : '' ?>>
                  <?= htmlspecialchars($opt['name']) ?>
                </label>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (!empty($segments)): ?>
<div class="card mt-4">
  <div class="card-header fw-semibold">Suppression 관계 요약</div>
  <div class="card-body small">
    <?php
    $has_any = false;
    foreach ($segments as $seg) {
        $supp_ids = json_decode($seg['suppresses_segment_ids'] ?? '[]', true) ?: [];
        if (empty($supp_ids)) continue;
        $has_any = true;
        $targets = [];
        foreach ($supp_ids as $sid) {
            $targets[] = $seg_name_map[$sid] ?? '(삭제됨)';
        }
        echo '<div class="mb-1">'
           . '<strong>' . htmlspecialchars($seg['name']) . '</strong>'
           . ' &rarr; ' . htmlspecialchars(implode(', ', $targets))
           . '</div>';
    }
    if (!$has_any) {
        echo '<span class="text-muted">현재 설정된 suppression 관계가 없습니다.</span>';
    }
    ?>
  </div>
</div>
<?php endif; ?>

<div id="save-toast" class="toast align-items-center text-bg-success border-0 position-fixed bottom-0 end-0 m-3" role="alert">
  <div class="d-flex">
    <div class="toast-body">저장 완료</div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
  </div>
</div>

<script>
const APP_URL = '<?= APP_URL ?>';
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
