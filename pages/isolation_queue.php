<?php
// pages/isolation_queue.php
// G4 — needs_manual_review 격리 큐 통합 대시보드.
// 운영자가 한 화면에서 격리된 캠페인을 보고 각각 결정 (scheduled / failed) 가능.
$title   = '격리 큐';
$scripts = ['campaign-actions.js']; // resolveReview 함수 재사용
include __DIR__ . '/../pages/layout_header.php';

$rows = DB::all(
    "SELECT id, name, segment_name, send_time, marketo_email_program_id,
            error_message, updated_at
       FROM campaigns
      WHERE status = 'needs_manual_review'
      ORDER BY updated_at DESC"
);
?>
<h2>격리 큐 <small class="text-muted fs-6">— needs_manual_review 상태 통합 관리</small></h2>

<div class="alert alert-info small mt-3">
  <strong>이 큐는 EP 변경 도중 실패하여 Marketo 측 상태가 불확실한 캠페인들의 모음입니다.</strong>
  각 항목을 <strong>Marketo UI</strong>에서 확인 후 결정하세요:<br>
  • <strong>Marketo에서 정상 예약됨 확인</strong> → <code>scheduled</code>로 표시 (같은 세그먼트 sibling 차단 유지)<br>
  • <strong>Marketo 측 미처리 확인</strong> → <code>failed</code>로 표시 (sibling 차단 해제)
</div>

<?php if (empty($rows)): ?>
  <div class="card border-success">
    <div class="card-body text-center text-muted py-5">
      <div class="fs-1">✅</div>
      <div class="mt-2">격리된 캠페인이 없습니다.</div>
    </div>
  </div>
<?php else: ?>
  <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
    <span class="text-muted small">총 <strong class="text-danger"><?= count($rows) ?></strong>건 격리됨</span>
  </div>

  <?php foreach ($rows as $c): ?>
    <div class="card border-danger mb-3" id="iso-card-<?= htmlspecialchars($c['id']) ?>">
      <div class="card-header bg-danger bg-opacity-10 d-flex justify-content-between align-items-center">
        <div>
          <strong><?= htmlspecialchars($c['name']) ?></strong>
          <span class="text-muted ms-2">[<?= htmlspecialchars($c['segment_name']) ?>]</span>
          <span class="badge bg-danger ms-2">needs_manual_review</span>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/campaigns/<?= htmlspecialchars($c['id']) ?>">상세 →</a>
      </div>
      <div class="card-body">
        <dl class="row mb-2 small">
          <dt class="col-sm-3">발송 일시</dt>
          <dd class="col-sm-9"><?= htmlspecialchars(substr($c['send_time'] ?? '', 0, 16)) ?></dd>
          <dt class="col-sm-3">Marketo EP/SC ID</dt>
          <dd class="col-sm-9">
            <?php if (!empty($c['marketo_email_program_id'])): ?>
              <code><?= htmlspecialchars($c['marketo_email_program_id']) ?></code>
              <small class="text-muted ms-2">Marketo UI 에서 이 EP 또는 SC 상태 직접 확인 필요</small>
            <?php else: ?>
              <span class="text-muted">없음 (EP/SC 변경 진입 전 실패)</span>
            <?php endif; ?>
          </dd>
          <dt class="col-sm-3">마지막 갱신</dt>
          <dd class="col-sm-9"><?= htmlspecialchars($c['updated_at']) ?></dd>
        </dl>

        <?php if ($c['error_message']): ?>
          <details class="mb-3">
            <summary class="small text-muted">에러 메시지 보기</summary>
            <pre class="mt-2 small bg-light p-2 rounded" style="white-space:pre-wrap;font-size:11px;"><?= htmlspecialchars($c['error_message']) ?></pre>
          </details>
        <?php endif; ?>

        <div class="row g-2">
          <div class="col-md-7">
            <input type="text" class="form-control form-control-sm" id="iso-note-<?= htmlspecialchars($c['id']) ?>"
                   placeholder="결정 메모 (선택): 예) Marketo UI 확인 결과 EP가 정상 scheduled 상태임">
          </div>
          <div class="col-md-5 d-flex gap-2">
            <button type="button" class="btn btn-success btn-sm flex-fill"
                    data-iso-resolve="<?= htmlspecialchars($c['id']) ?>" data-as="scheduled">
              ✓ Scheduled로
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm flex-fill"
                    data-iso-resolve="<?= htmlspecialchars($c['id']) ?>" data-as="failed">
              ✗ Failed로
            </button>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<script>
const APP_URL = '<?= APP_URL ?>';

// 카드별 resolve 핸들러 — 본 페이지 내부에서만 사용.
// /api/campaigns/{id}/resolve-review 와 동일 endpoint.
document.querySelectorAll('[data-iso-resolve]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const id    = btn.getAttribute('data-iso-resolve');
    const as    = btn.getAttribute('data-as');
    const note  = (document.getElementById('iso-note-' + id) || {}).value || '';
    if (!confirm(`캠페인을 "${as}" 상태로 변경합니다. 진행할까요?`)) return;
    btn.disabled = true;
    try {
      const res = await fetch(`${APP_URL}/api/campaigns/${encodeURIComponent(id)}/resolve-review`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ as, operator_note: note }),
      });
      const json = await res.json();
      if (json.success) {
        const card = document.getElementById('iso-card-' + id);
        if (card) {
          card.classList.add('opacity-50');
          card.querySelectorAll('button, input').forEach(el => el.disabled = true);
          const badge = card.querySelector('.badge');
          if (badge) { badge.textContent = as; badge.className = 'badge ms-2 bg-' + (as === 'scheduled' ? 'success' : 'secondary'); }
        }
      } else {
        alert('해제 실패: ' + (json.error || '알 수 없는 오류'));
        btn.disabled = false;
      }
    } catch (e) {
      alert('네트워크 오류: ' + e.message);
      btn.disabled = false;
    }
  });
});
</script>

<?php include __DIR__ . '/../pages/layout_footer.php'; ?>
