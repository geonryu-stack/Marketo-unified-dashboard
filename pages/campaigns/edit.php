<?php
// pages/campaigns/edit.php — $id는 router에서 주입
$c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
if (!$c) { header('Location: ' . APP_URL . '/campaigns'); exit; }

if (!in_array($c['status'], ['awaiting_approval', 'failed', 'draft'], true)) {
    header('Location: ' . APP_URL . '/campaigns/' . $id);
    exit;
}

$title    = '캠페인 편집';
$segments = DB::all('SELECT id, name FROM segments ORDER BY name');

$email_lib_program_id = (defined('MARKETO_EMAIL_ASSET_LIBRARY_ID') && (int)MARKETO_EMAIL_ASSET_LIBRARY_ID > 0)
    ? (int)MARKETO_EMAIL_ASSET_LIBRARY_ID : 0;

// send_time 기본값: 기존 값 그대로 (datetime-local 형식으로 변환)
$raw_st       = $c['send_time'] ?? '';
$default_send = strlen($raw_st) > 5
    ? date('Y-m-d\TH:i', strtotime($raw_st))
    : (date('Y-m-d', strtotime('+1 day')) . 'T' . ($raw_st ?: '10:00'));

$scripts = ['campaign.js'];
include __DIR__ . '/../layout_header.php';
?>
<div class="d-flex align-items-center gap-3 mb-3">
  <h2 class="mb-0">캠페인 편집</h2>
  <a href="<?= APP_URL ?>/campaigns/<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary">← 상세로</a>
</div>
<p class="text-muted">저장하면 테스트 메일이 다시 발송됩니다.</p>

<div class="row g-4">
  <div class="col-md-6">
    <form id="edit-form">

      <div class="mb-3">
        <label class="form-label">캠페인 이름 *</label>
        <input type="text" class="form-control" name="name" required
               value="<?= htmlspecialchars($c['name']) ?>">
      </div>

      <div class="mb-3">
        <label class="form-label">세그먼트 *</label>
        <select class="form-select" name="segment_id" required>
          <option value="">선택하세요</option>
          <?php foreach ($segments as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $c['segment_id'] === $s['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">이메일 에셋 *
          <span class="text-muted fw-normal small">(Library Program ID: <?= $email_lib_program_id ?>)</span>
        </label>
        <select class="form-select" name="marketo_cloned_email_id" id="email-asset-select" required>
          <option value="">로딩 중...</option>
        </select>
        <div class="form-text" id="email-asset-count"></div>
        <input type="hidden" name="asset_name" id="asset-name-input"
               value="<?= htmlspecialchars($c['asset_name'] ?? '') ?>">
      </div>

      <hr class="my-4">
      <h5 class="mb-3">이메일 컨텐츠 <small class="text-muted fw-normal">— Marketo My Token으로 주입됩니다</small></h5>

      <div class="mb-3">
        <label class="form-label">콘텐츠 프리셋 <small class="text-muted fw-normal">(선택 시 이모지·제목·프리헤더 일괄 채움)</small></label>
        <div class="input-group">
          <select class="form-select" name="content_preset"></select>
          <button type="button" class="btn btn-outline-secondary" id="btn-manage-presets" title="프리셋 관리">＋ 관리</button>
        </div>
      </div>

      <div class="row g-2 mb-3">
        <div class="col-auto">
          <label class="form-label">이모지 <code class="small">{{my.Emoji}}</code></label>
          <input type="text" class="form-control" name="emoji" style="width:90px"
                 value="<?= htmlspecialchars($c['emoji'] ?? '') ?>" placeholder="🎁">
        </div>
        <div class="col">
          <label class="form-label">이메일 제목 <code class="small">{{my.Title}}</code></label>
          <input type="text" class="form-control" name="email_title"
                 value="<?= htmlspecialchars($c['email_title'] ?? '') ?>"
                 placeholder="이메일 제목을 입력하세요">
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">프리헤더 <code class="small">{{my.Preheader}}</code></label>
        <input type="text" class="form-control" name="email_preheader"
               value="<?= htmlspecialchars($c['email_preheader'] ?? '') ?>"
               placeholder="수신함에서 미리 보이는 짧은 텍스트">
      </div>
      <div class="mb-3">
        <label class="form-label">보상 URL <code class="small">{{my.RewardUrl}}</code></label>
        <input type="url" class="form-control" name="reward_url"
               value="<?= htmlspecialchars($c['reward_url'] ?? '') ?>"
               placeholder="https://...">
      </div>

      <hr class="my-4">

      <div class="mb-3" style="max-width:320px">
        <label class="form-label">이메일 발송 일시 * <span class="text-muted fw-normal small">(수신자 현지시간)</span></label>
        <input type="datetime-local" class="form-control" name="send_time" required
               value="<?= $default_send ?>"
               min="<?= date('Y-m-d\TH:i', strtotime('+17 hours')) ?>">
        <div class="form-text">대상자 추출은 발송 16시간 전에 자동 실행됩니다. (최소 17시간 이후 선택)</div>
      </div>

      <button type="submit" class="btn btn-primary" id="submit-btn">저장 및 테스트 메일 재발송</button>
      <a href="<?= APP_URL ?>/campaigns/<?= $c['id'] ?>" class="btn btn-outline-secondary ms-2">취소</a>
    </form>
  </div>

  <div class="col-md-6">
    <div id="inbox-preview" class="sticky-top" style="top:1rem;"></div>
  </div>
</div>

<script>
const APP_URL        = '<?= APP_URL ?>';
const CAMPAIGN_ID    = '<?= $c['id'] ?>';
const EMAIL_LIB_ID   = <?= $email_lib_program_id ?>;
const CURRENT_EMAIL_ID = <?= (int)($c['marketo_cloned_email_id'] ?? 0) ?>;

async function loadEmailAssets() {
  const sel   = document.getElementById('email-asset-select');
  const count = document.getElementById('email-asset-count');
  if (!EMAIL_LIB_ID) {
    sel.replaceChildren(new Option('config에 MARKETO_EMAIL_ASSET_LIBRARY_ID를 설정하세요', ''));
    return;
  }
  try {
    const res  = await fetch(`${APP_URL}/api/marketo/emails?program_id=${EMAIL_LIB_ID}`);
    const data = await res.json();
    if (!data.success) { sel.replaceChildren(new Option(`에셋 로드 오류: ${data.error}`, '')); return; }
    const emails = data.data ?? [];
    sel.replaceChildren(new Option('선택하세요', ''));
    emails.forEach(e => {
      const opt = new Option(`${e.name} (#${e.id})`, String(e.id));
      opt.dataset.name = e.name;
      sel.appendChild(opt);
    });
    if (CURRENT_EMAIL_ID) sel.value = String(CURRENT_EMAIL_ID);
    const selected = sel.options[sel.selectedIndex];
    if (selected?.value) document.getElementById('asset-name-input').value = selected.dataset.name ?? '';
    count.textContent = emails.length ? `${emails.length}개 에셋` : '에셋 없음';
  } catch {
    sel.replaceChildren(new Option('에셋 로드 실패 — Marketo 연결 확인', ''));
  }
}

document.getElementById('email-asset-select').addEventListener('change', (e) => {
  const opt = e.target.options[e.target.selectedIndex];
  document.getElementById('asset-name-input').value = opt?.dataset.name ?? '';
});

loadEmailAssets();

// Sprint 2 ASSET — 라이브 인박스 미리보기 + 프리셋 드롭다운
// campaign.js는 footer에서 로드되므로 DOMContentLoaded(혹은 이미 끝났으면 즉시) 시점에 호출.
function _bootLivePreview() {
  if (typeof window.initLivePreview === 'function') {
    window.initLivePreview('#edit-form', '#inbox-preview');
  } else {
    setTimeout(_bootLivePreview, 50);
  }
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', _bootLivePreview);
} else {
  _bootLivePreview();
}

// Sprint 3 ASSET — 프리셋 관리 모달 트리거
document.getElementById('btn-manage-presets')?.addEventListener('click', () => {
  const sel = document.querySelector('#edit-form select[name="content_preset"]');
  if (typeof window.openPresetManagerModal === 'function') {
    window.openPresetManagerModal(sel);
  }
});

document.getElementById('edit-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = document.getElementById('submit-btn');
  btn.disabled = true;
  btn.textContent = '테스트 메일 발송 중...';
  const f = e.target;
  const body = {
    name:                    f.name.value,
    segment_id:              f.segment_id.value,
    asset_name:              f.asset_name.value,
    marketo_cloned_email_id: f.marketo_cloned_email_id.value,
    emoji:                   f.emoji.value,
    email_title:             f.email_title.value,
    email_preheader:         f.email_preheader.value,
    reward_url:              f.reward_url.value,
    send_time:               f.send_time.value,
  };
  try {
    const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/save`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    });
    const data = await res.json();
    if (data.success) location.href = `${APP_URL}/campaigns/${CAMPAIGN_ID}`;
    else { alert('저장 실패: ' + data.error); btn.disabled = false; btn.textContent = '저장 및 테스트 메일 재발송'; }
  } catch {
    alert('네트워크 오류'); btn.disabled = false; btn.textContent = '저장 및 테스트 메일 재발송';
  }
});
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
