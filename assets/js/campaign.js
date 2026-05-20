// assets/js/campaign.js

const campaign = {
  // ── 결재 카드 체크리스트 게이팅 ─────────────────────────────
  _bindApprovalChecklist() {
    const boxes = document.querySelectorAll('.approval-check');
    const btn   = document.getElementById('btn-approve');
    if (!boxes.length || !btn) return;
    const sync = () => {
      const allChecked = Array.from(boxes).every(b => b.checked);
      btn.disabled = !allChecked;
    };
    boxes.forEach(b => b.addEventListener('change', sync));
    sync();
  },

  // ── 결재 승인 ───────────────────────────────────────────────
  async approve() {
    const boxes = document.querySelectorAll('.approval-check');
    const allChecked = Array.from(boxes).every(b => b.checked);
    if (!allChecked) {
      alert('체크리스트 4개를 모두 확인해야 승인할 수 있습니다.');
      return;
    }

    // Step 1 — type-to-confirm 모달
    const confirmed = await campaign._confirmApprove();
    if (!confirmed) return;

    // Step 2 — 진행 모달 열기 + logs polling 시작
    const progress = campaign._showProgressModal();
    const pollTimer = setInterval(loadLogs, 1500);

    try {
      const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/approve`, { method: 'POST' });
      const data = await res.json();
      clearInterval(pollTimer);

      if (data.success) {
        progress.setState('success', '✅ Marketo 예약이 완료되었습니다. 페이지를 새로고침합니다...');
        setTimeout(() => location.reload(), 1200);
      } else if (data.conflict_id) {
        progress.setState('error', `세그먼트 충돌: ${data.error}`, [
          { label: '충돌 캠페인 열기', cls: 'btn-primary', onclick: () => { location.href = `${APP_URL}/campaigns/${data.conflict_id}`; } },
          { label: '닫기',             cls: 'btn-outline-secondary', onclick: () => progress.close() },
        ]);
      } else {
        progress.setState('error', `예약 실패: ${data.error || '알 수 없는 오류'}`, [
          { label: '페이지 새로고침', cls: 'btn-primary', onclick: () => location.reload() },
          { label: '닫기',            cls: 'btn-outline-secondary', onclick: () => progress.close() },
        ]);
      }
    } catch (e) {
      clearInterval(pollTimer);
      progress.setState('error', `네트워크 오류: ${e.message}`, [
        { label: '페이지 새로고침', cls: 'btn-primary', onclick: () => location.reload() },
      ]);
    }
  },

  // ── 결재 거절 ───────────────────────────────────────────────
  async reject() {
    const memo = prompt('거절 사유 메모 (선택, 비워두어도 됩니다):\n→ 캠페인을 draft로 되돌립니다.', '');
    if (memo === null) return; // 취소
    try {
      const res = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/reject`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ reject_memo: memo }),
      });
      const data = await res.json();
      if (data.success) location.reload();
      else alert('거절 실패: ' + (data.error || '알 수 없는 오류'));
    } catch (e) {
      alert('네트워크 오류: ' + e.message);
    }
  },

  // ── 테스트 메일 재발송 ──────────────────────────────────────
  async resendTestEmail() {
    if (!confirm('테스트 메일을 다시 발송하시겠습니까?')) return;
    try {
      const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/resend-test-email`, { method: 'POST' });
      const data = await res.json();
      if (data.success) {
        alert('테스트 메일을 재발송했습니다. 메일함을 확인하세요.');
        location.reload();
      } else {
        alert('재발송 실패: ' + (data.error || '알 수 없는 오류'));
      }
    } catch (e) {
      alert('네트워크 오류: ' + e.message);
    }
  },

  // ── 테스트 메일 스크린샷 첨부 (Sprint 1 ASSET) ───────────────
  async uploadScreenshot() {
    const input = document.getElementById('screenshot-file');
    if (!input || !input.files || !input.files[0]) {
      alert('첨부할 이미지 파일을 선택하세요.');
      return;
    }
    const file = input.files[0];
    // 클라이언트 측 1차 가드: 5MB / 화이트리스트 MIME (서버에서 재검증)
    const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];
    if (!ALLOWED.includes(file.type)) {
      alert('jpg, png, webp 형식만 업로드 가능합니다.');
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      alert('파일 크기는 5MB 이하여야 합니다.');
      return;
    }

    const fd = new FormData();
    fd.append('file', file);

    const btn = event && event.target ? event.target : null;
    if (btn) { btn.disabled = true; btn.textContent = '업로드 중...'; }

    try {
      const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/screenshot`, {
        method: 'POST',
        body:   fd,
      });
      const data = await res.json();
      if (data.success) {
        // 즉시 페이지 리로드: 첨부 슬롯이 썸네일 모드로 전환됨
        location.reload();
      } else {
        alert('스크린샷 첨부 실패: ' + (data.error || '알 수 없는 오류'));
        if (btn) { btn.disabled = false; btn.textContent = '첨부'; }
      }
    } catch (e) {
      alert('네트워크 오류: ' + e.message);
      if (btn) { btn.disabled = false; btn.textContent = '첨부'; }
    }
  },

  // ── 기존 액션 ───────────────────────────────────────────────
  async _action(action, method = 'POST') {
    const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/${action}`, { method });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.error || '오류가 발생했습니다.');
  },

  cancel() {
    if (confirm('Marketo 예약을 취소하시겠습니까?\n취소 후 캠페인은 결재 대기 상태로 돌아갑니다.'))
      this._action('cancel');
  },

  async duplicate() {
    if (!confirm('동일 설정으로 새 캠페인을 만드시겠습니까?\n발송 일시는 내일 같은 시각으로 설정됩니다.')) return;
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = '복제 중...';
    const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/duplicate`, { method: 'POST' });
    const data = await res.json();
    if (data.success) location.href = `${APP_URL}/campaigns/${data.data.id}`;
    else { alert('복사 실패: ' + data.error); btn.disabled = false; btn.textContent = '다음 회차 복제'; }
  },

  deleteCampaign() {
    if (!confirm('삭제하시겠습니까? 복구할 수 없습니다.')) return;
    fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}`, { method: 'DELETE' })
      .then(() => { location.href = `${APP_URL}/campaigns`; });
  },

  async resolveReview(as) {
    const messages = {
      scheduled: '⚠️ Marketo UI에서 해당 Email Program이 정상 예약(scheduled)된 것을 확인했습니까?\n\n' +
                 '"확인"을 누르면 캠페인 상태를 \'예약 완료\'로 표시하고, 같은 세그먼트의 다른 캠페인 차단을 유지합니다.\n' +
                 '잘못 확인하면 발송이 누락될 수 있습니다.',
      failed:    '⚠️ Marketo UI에서 해당 Email Program이 미처리(draft/unapproved) 또는 정리됨을 확인했습니까?\n\n' +
                 '"확인"을 누르면 캠페인 상태를 \'실패\'로 표시하고, sibling 차단을 해제합니다.\n' +
                 '실제로 Marketo에 예약돼 있다면 다른 캠페인이 그 예약을 덮어쓸 수 있습니다.',
    };
    if (!confirm(messages[as])) return;

    const note = (document.getElementById('review-note')?.value || '').trim();
    try {
      const res = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/resolve-review`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ as, operator_note: note }),
      });
      const data = await res.json();
      if (data.success) {
        location.reload();
      } else {
        alert('해제 실패: ' + (data.error || '알 수 없는 오류'));
      }
    } catch (e) {
      alert('네트워크 오류: ' + e.message);
    }
  },

  // ── 모달 헬퍼 ───────────────────────────────────────────────
  _confirmApprove() {
    // 한글 "발송" 두 글자 입력해야 활성화 (단순 confirm 키 연타 방지)
    return new Promise((resolve) => {
      const html = `
        <div class="modal fade" id="approve-confirm-modal" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">⚠️ 발송 승인 — 되돌릴 수 없습니다</h5>
              </div>
              <div class="modal-body">
                <p>승인 즉시 다음 작업이 동기로 실행됩니다 (1~5분):</p>
                <ul class="small text-muted mb-3">
                  <li>사내 DB에서 대상자 추출</li>
                  <li>Marketo Static List 업로드 (REST 또는 Bulk)</li>
                  <li>토큰 주입 + Email Program 예약 (RTZ)</li>
                </ul>
                <hr>
                <label class="form-label">아래 입력란에 <strong>발송</strong> 을 그대로 입력하세요:</label>
                <input type="text" class="form-control" id="confirm-input" autocomplete="off" placeholder="발송">
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-success" id="confirm-go" disabled>확인하고 승인</button>
              </div>
            </div>
          </div>
        </div>`;
      document.body.insertAdjacentHTML('beforeend', html);
      const modalEl = document.getElementById('approve-confirm-modal');
      const input   = document.getElementById('confirm-input');
      const goBtn   = document.getElementById('confirm-go');
      const modal   = new bootstrap.Modal(modalEl);

      input.addEventListener('input', () => {
        goBtn.disabled = input.value.trim() !== '발송';
      });
      goBtn.addEventListener('click', () => { modal.hide(); resolve(true); });
      modalEl.addEventListener('hidden.bs.modal', () => {
        modalEl.remove();
        if (!goBtn.dataset.fired) resolve(false);
      });
      goBtn.addEventListener('click', () => { goBtn.dataset.fired = '1'; });
      modal.show();
      setTimeout(() => input.focus(), 200);
    });
  },

  _showProgressModal() {
    const html = `
      <div class="modal fade" id="approve-progress-modal" tabindex="-1"
           data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="progress-title">⏳ Marketo 예약 진행 중 (1~5분 소요)</h5>
            </div>
            <div class="modal-body">
              <div class="alert alert-info py-2 small mb-3" id="progress-alert">
                페이지를 닫지 마세요. 단계별 진행 상황은 아래 로그 테이블이 실시간으로 갱신합니다.
              </div>
              <div class="d-flex align-items-center mb-3" id="progress-spinner">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                <span>대상자 추출 → Marketo 업로드 → Email Program 예약 진행 중...</span>
              </div>
              <div id="progress-actions" class="d-flex gap-2"></div>
            </div>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
    const modalEl = document.getElementById('approve-progress-modal');
    const modal   = new bootstrap.Modal(modalEl);
    modal.show();
    modalEl.addEventListener('hidden.bs.modal', () => modalEl.remove());

    return {
      setState(kind, message, actions = []) {
        const alert  = document.getElementById('progress-alert');
        const spin   = document.getElementById('progress-spinner');
        const title  = document.getElementById('progress-title');
        const acts   = document.getElementById('progress-actions');
        spin.style.display = 'none';
        if (kind === 'success') {
          title.textContent = '✅ 예약 완료';
          alert.className   = 'alert alert-success py-2 mb-3';
          alert.textContent = message;
        } else if (kind === 'error') {
          title.textContent = '❌ 예약 실패';
          alert.className   = 'alert alert-danger py-2 mb-3';
          alert.textContent = message;
          acts.innerHTML = '';
          actions.forEach(a => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn ' + a.cls;
            btn.textContent = a.label;
            btn.addEventListener('click', a.onclick);
            acts.appendChild(btn);
          });
        }
      },
      close() { modal.hide(); },
    };
  },
};

// 페이지 로드 시 체크리스트 바인딩 + ASSET 길이가이드/URL가드
document.addEventListener('DOMContentLoaded', () => {
  campaign._bindApprovalChecklist();
  initLengthGuides();
  attachRewardUrlGuard();
});

// ──────────────────────────────────────────────────────────
// Sprint 0 ASSET — 길이 가이드 & URL 검증/정규화
// ──────────────────────────────────────────────────────────

/**
 * 가중치 기반 문자 길이.
 * ASCII printable(공백/영숫자/기호)은 1, 그 외(한글, 이모지 등)는 2로 카운트.
 * 한글 1자가 영문 약 2자에 해당하는 시각적 폭을 반영 (Marketo 제목 미리보기 기준).
 */
function weightedLength(s) {
  if (!s) return 0;
  let n = 0;
  for (const ch of [...s]) {            // surrogate pair (이모지) 1자 처리
    n += /^[\x20-\x7E]$/.test(ch) ? 1 : 2;
  }
  return n;
}

const LENGTH_GUIDES = {
  email_title:     { warn: 30, over: 50,  label: '한글 기준' },
  email_preheader: { warn: 90, over: 140, label: '문자' },
};

function ensureCounterFor(input) {
  let counter = input.parentElement?.querySelector(`:scope > .char-counter[data-for="${input.name}"]`);
  if (counter) return counter;
  counter = document.createElement('div');
  counter.className = 'form-text char-counter';
  counter.dataset.for = input.name;
  const existing = input.parentElement?.querySelector(':scope > .form-text');
  if (existing && existing !== counter) existing.parentElement.insertBefore(counter, existing);
  else input.insertAdjacentElement('afterend', counter);
  return counter;
}

function updateCounter(input, guide) {
  const counter = ensureCounterFor(input);
  const len = weightedLength(input.value);
  counter.textContent = `${len}/${guide.warn} (${guide.label})`;
  counter.classList.remove('char-warn', 'char-over');
  if (len > guide.over) counter.classList.add('char-over');
  else if (len > guide.warn) counter.classList.add('char-warn');
}

function initLengthGuides() {
  for (const [name, guide] of Object.entries(LENGTH_GUIDES)) {
    const input = document.querySelector(`input[name="${name}"]`);
    if (!input) continue;
    updateCounter(input, guide);
    input.addEventListener('input', () => updateCounter(input, guide));
  }
}

// ──────────────────────────────────────────────────────────
// Sprint 2 ASSET — 라이브 인박스 미리보기 + 콘텐츠 프리셋
// ──────────────────────────────────────────────────────────

/**
 * 콘텐츠 프리셋 (v1 — JS 상수). 운영자가 드롭다운으로 선택해
 * emoji/email_title/email_preheader 3개 input에 일괄 주입.
 * reward_url은 회차마다 다르므로 프리셋에 포함되지 않음.
 * v2에서 content_presets 테이블 + /api/content-presets 로 이관 예정.
 */
const CONTENT_PRESETS = [
  {
    label:     '🎁 기본 보상 안내',
    emoji:     '🎁',
    title:     '보상이 도착했어요',
    preheader: '오늘만 확인 가능한 혜택을 지금 받아가세요.',
  },
  {
    label:     '⏰ 만료 임박 리마인더',
    emoji:     '⏰',
    title:     '오늘 자정에 사라져요',
    preheader: '아직 확인하지 않은 보상이 있어요. 놓치지 마세요.',
  },
  {
    label:     '🔥 한정 이벤트',
    emoji:     '🔥',
    title:     '단 24시간, 특별 이벤트',
    preheader: '이번 주말까지만 진행하는 한정 보상 안내입니다.',
  },
  {
    label:     '✨ 신규 캠페인 시작',
    emoji:     '✨',
    title:     '새로운 캠페인이 시작됐어요',
    preheader: '회원님께 어울리는 새로운 혜택을 준비했습니다.',
  },
  {
    label:     '💌 위클리 리포트',
    emoji:     '💌',
    title:     '이번 주의 활동 요약',
    preheader: '지난 한 주 동안의 활동과 다음 보상을 한눈에 확인하세요.',
  },
  {
    label:     '🎯 맞춤 추천',
    emoji:     '🎯',
    title:     '회원님께 딱 맞는 보상',
    preheader: '회원님의 활동을 분석해 추천드리는 맞춤 혜택이에요.',
  },
  {
    label:     '🎉 축하 메시지',
    emoji:     '🎉',
    title:     '축하해요! 새 보상이 열렸어요',
    preheader: '회원님께만 드리는 특별한 축하 보상을 확인하세요.',
  },
  {
    label:     '🚀 빠른 액션 유도',
    emoji:     '🚀',
    title:     '지금 바로 시작해 보세요',
    preheader: '한 번의 클릭으로 보상을 받을 수 있어요.',
  },
];

/** HTML 이스케이프 (미리보기에서 사용자 입력 그대로 노출하지 않도록). */
function _escHtml(s) {
  if (s === undefined || s === null) return '';
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * 인박스 미리보기 1회 렌더.
 * - mime_header_value의 base64 인코딩은 흉내 안 함 (UTF-8 그대로 노출).
 * - 본문은 placeholder + reward_url 버튼만 노출 (실제 본문은 Marketo 에셋이 결정).
 */
function _renderInboxPreview(container, fields) {
  const emoji     = (fields.emoji || '').trim();
  const title     = (fields.email_title || '').trim();
  const preheader = (fields.email_preheader || '').trim();
  const rewardUrl = normalizeRewardUrl(fields.reward_url || '');

  const titleHtml = emoji
    ? `<span class="inbox-emoji">${_escHtml(emoji)}</span> ${_escHtml(title || '(제목 없음)')}`
    : _escHtml(title || '(제목 없음)');

  const urlValid = isValidRewardUrl(rewardUrl);
  const urlBadge = !rewardUrl
    ? '<span class="text-muted small">(보상 URL 미입력)</span>'
    : !urlValid
      ? `<span class="text-danger small">⚠ 잘못된 URL: ${_escHtml(rewardUrl)}</span>`
      : `<a href="#" onclick="return false;" class="inbox-cta" title="${_escHtml(rewardUrl)}">보상 받기 →</a>`;

  container.innerHTML = `
    <div class="inbox-preview">
      <div class="inbox-preview-label small text-muted mb-2">📬 수신함 미리보기 (Gmail 기준)</div>
      <div class="inbox-emoji-title">${titleHtml}</div>
      <div class="inbox-preheader">${_escHtml(preheader || '(프리헤더 미입력 — 본문 첫 줄이 대신 노출됩니다)')}</div>
      <hr class="my-3">
      <div class="inbox-body small text-muted">
        (이메일 본문은 Marketo 에셋에 정의됨)
      </div>
      <div class="inbox-cta-wrap mt-3">${urlBadge}</div>
    </div>
  `;
}

/**
 * 폼 4개 input(emoji/email_title/email_preheader/reward_url) 값을
 * Gmail 스타일 카드로 즉시 렌더. ORCH의 통합 결재 카드에서도 같은 함수 재사용 가능.
 *
 * @param {string} formSelector    기본 '#campaign-form'
 * @param {string} previewSelector 기본 '#inbox-preview'
 */
window.initLivePreview = function (formSelector = '#campaign-form', previewSelector = '#inbox-preview') {
  const form    = document.querySelector(formSelector);
  const preview = document.querySelector(previewSelector);
  if (!form || !preview) return;

  const collect = () => ({
    emoji:           form.querySelector('input[name="emoji"]')?.value           ?? '',
    email_title:     form.querySelector('input[name="email_title"]')?.value     ?? '',
    email_preheader: form.querySelector('input[name="email_preheader"]')?.value ?? '',
    reward_url:      form.querySelector('input[name="reward_url"]')?.value      ?? '',
  });

  const render = () => _renderInboxPreview(preview, collect());

  ['emoji', 'email_title', 'email_preheader', 'reward_url'].forEach(name => {
    const input = form.querySelector(`input[name="${name}"]`);
    if (!input) return;
    input.addEventListener('input', render);
  });

  // 프리셋 드롭다운: 선택 시 3개 input 채우고 미리보기 갱신
  const presetSelect = form.querySelector('select[name="content_preset"]');
  if (presetSelect && !presetSelect.dataset.populated) {
    presetSelect.dataset.populated = '1';
    presetSelect.appendChild(new Option('— 프리셋을 선택하세요 —', ''));
    CONTENT_PRESETS.forEach((p, i) => {
      presetSelect.appendChild(new Option(p.label, String(i)));
    });
    presetSelect.addEventListener('change', (e) => {
      const idx = parseInt(e.target.value, 10);
      if (isNaN(idx) || !CONTENT_PRESETS[idx]) return;
      const p = CONTENT_PRESETS[idx];
      const emoji = form.querySelector('input[name="emoji"]');
      const title = form.querySelector('input[name="email_title"]');
      const pre   = form.querySelector('input[name="email_preheader"]');
      if (emoji) emoji.value = p.emoji;
      if (title) title.value = p.title;
      if (pre)   pre.value   = p.preheader;
      // 길이 가이드 카운터 갱신
      [emoji, title, pre].forEach(inp => inp?.dispatchEvent(new Event('input', { bubbles: true })));
      render();
    });
  }

  render();
};

/** 보상 URL 정규화: trim + 줄바꿈/탭 제거 (후행 슬래시는 유지). */
function normalizeRewardUrl(s) {
  if (!s) return '';
  return String(s).replace(/[\r\n\t]/g, '').trim();
}

/** 빈 문자열은 통과. https?:// 시작 + URL 생성자 parse 성공이어야 유효. */
function isValidRewardUrl(s) {
  if (!s) return true;
  if (!/^https?:\/\//i.test(s)) return false;
  try { new URL(s); return true; } catch { return false; }
}

/**
 * new/edit 폼의 submit 직전 reward_url 정규화 + 검증.
 * - capture 단계에 등록 → 기존 submit 핸들러보다 먼저 실행.
 * - 실패 시 stopImmediatePropagation으로 후속 핸들러 차단.
 */
function attachRewardUrlGuard() {
  const forms = document.querySelectorAll('#campaign-form, #edit-form');
  forms.forEach(form => {
    form.addEventListener('submit', (e) => {
      const input = form.querySelector('input[name="reward_url"]');
      if (!input) return;
      const normalized = normalizeRewardUrl(input.value);
      if (normalized !== input.value) input.value = normalized;
      if (!isValidRewardUrl(normalized)) {
        e.preventDefault();
        e.stopImmediatePropagation();
        alert('보상 URL이 유효하지 않습니다. https:// 또는 http://로 시작하는 올바른 URL을 입력하세요.');
        input.focus();
      }
    }, true);
  });
}

// Bulk Import 진행 카드 갱신 (status='bulk_polling'인 동안만)
let _bulkTimer = null;

async function loadBulkProgress() {
  const card = document.getElementById('bulk-progress-card');
  if (!card) return;
  try {
    const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}`);
    const data = await res.json();
    if (!data.success) return;
    const c = data.data;
    // 상태가 bulk_polling을 벗어나면 페이지 리로드 (UI 전체 갱신)
    if (c.status !== 'bulk_polling') {
      location.reload();
      return;
    }
    const badge = document.getElementById('bulk-status-badge');
    if (badge) badge.textContent = c.bulk_status || 'Importing';
    if (!_bulkTimer) _bulkTimer = setInterval(loadBulkProgress, 30000);
  } catch (_) {}
}

if (typeof CAMPAIGN_STATUS !== 'undefined' && CAMPAIGN_STATUS === 'bulk_polling') {
  loadBulkProgress();
}

// 발송 결과 카드 갱신
let _deliveryTimer = null;

async function loadDeliveryStats() {
  const card = document.getElementById('delivery-stats');
  if (!card) return;
  try {
    const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/delivery-result`);
    const data = await res.json();
    if (!data.success) return;
    const d           = data.data;
    const confirmed   = parseInt(d.sent_count)    || 0;
    const total       = parseInt(d.lead_count)    || 0;
    const delivered   = parseInt(d.delivered_count) || 0;
    const bounce      = parseInt(d.bounce_count)  || 0;
    const unconfirmed = Math.max(0, total - confirmed);
    const pct         = total > 0 ? ((confirmed / total) * 100).toFixed(1) : '0.0';
    card.innerHTML = `
      <p class="mb-1">발송: <strong>${total.toLocaleString()}명</strong> 중 <strong>${confirmed.toLocaleString()}명</strong> 확인 (<strong>${pct}%</strong>)</p>
      <p class="mb-1 text-muted small">도달: ${delivered.toLocaleString()} | 반송: ${bounce.toLocaleString()} | 미확인: ${unconfirmed.toLocaleString()}</p>
      <p class="mb-0 text-muted small">마지막 조회: ${d.activity_polled_at ? d.activity_polled_at.substring(0,16) : '-'}${d.poll_next_at ? ' | 다음 조회: ' + d.poll_next_at.substring(11,16) : ''}</p>`;
    if (d.poll_status !== (typeof POLL_STATUS !== 'undefined' ? POLL_STATUS : 'idle')) {
      location.reload();
    }
    if (d.poll_status === 'polling') {
      if (!_deliveryTimer) _deliveryTimer = setInterval(loadDeliveryStats, 30000);
    } else {
      clearInterval(_deliveryTimer);
      _deliveryTimer = null;
    }
  } catch (_) {}
}

if (typeof POLL_STATUS !== 'undefined' && POLL_STATUS !== 'idle') {
  loadDeliveryStats();
}

// 로그 폴링 (2초 간격)
async function loadLogs() {
  const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/logs`);
  const data = await res.json();
  if (!data.success) return;
  const tbody = document.getElementById('log-body');
  if (!tbody) return;
  tbody.innerHTML = '';
  data.data.forEach(log => {
    const cls = log.status === 'done' ? 'log-row-done' : log.status === 'error' ? 'log-row-error' : 'log-row-running';
    const tr = `<tr class="${cls}">
      <td>${log.step}</td>
      <td>${log.status}</td>
      <td>${log.message || ''}</td>
      <td>${log.created_at.substring(0,16)}</td>
    </tr>`;
    tbody.insertAdjacentHTML('beforeend', tr);
  });
}

// detail.php(CAMPAIGN_ID 정의됨)에서만 로그 폴링 활성화.
// new.php / edit.php는 campaign.js를 공유하지만 CAMPAIGN_ID가 없으므로 polling 생략.
if (typeof CAMPAIGN_ID !== 'undefined' && CAMPAIGN_ID && document.getElementById('log-body')) {
  loadLogs();
  setInterval(loadLogs, 2000);
}

// ── 5분 취소 윈도 + 카운트다운 (ORCH Sprint 0) ──────────────────────────
const URGENT_MS = 5 * 60 * 1000;

function formatRemaining(ms) {
  if (ms < 0) ms = 0;
  const totalSec = Math.floor(ms / 1000);
  const hh = Math.floor(totalSec / 3600);
  const mm = Math.floor((totalSec % 3600) / 60);
  const ss = totalSec % 60;
  const pad = (n) => String(n).padStart(2, '0');
  if (hh > 0) return `${pad(hh)}:${pad(mm)}:${pad(ss)}`;
  return `${pad(mm)}:${pad(ss)}`;
}

function initCancelCountdown() {
  const card  = document.getElementById('cancel-countdown');
  const clock = document.getElementById('cancel-countdown-clock');
  const btn   = document.getElementById('cancel-countdown-btn');
  if (!card || !clock || !btn) return;

  const sendTimeIso = card.getAttribute('data-send-time');
  if (!sendTimeIso) return;
  const targetMs = Date.parse(sendTimeIso);
  if (isNaN(targetMs)) return;

  let reloaded = false;

  const tick = () => {
    const remaining = targetMs - Date.now();

    if (remaining <= 0) {
      card.style.display = 'none';
      if (!reloaded) {
        reloaded = true;
        setTimeout(() => location.reload(), 500);
      }
      return;
    }

    clock.textContent = formatRemaining(remaining);

    if (remaining <= URGENT_MS) {
      card.classList.add('cancel-urgent');
      btn.classList.remove('btn-outline-danger');
      btn.classList.add('btn-danger');
      btn.textContent = '긴급 취소 (Marketo unapprove)';
    } else {
      card.classList.remove('cancel-urgent');
      btn.classList.add('btn-outline-danger');
      btn.classList.remove('btn-danger');
      btn.textContent = '예약 취소';
    }
  };

  tick();
  setInterval(tick, 1000);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initCancelCountdown);
} else {
  initCancelCountdown();
}

// ── Sprint 2 ORCH ⑮⑱ — 정적 인박스 미리보기 / 직전 회차 / 코호트 추세 ──

function _esc(s) {
  return String(s ?? '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function renderStaticInboxPreview(tokens, containerEl) {
  if (!containerEl) return;
  const emoji     = tokens.emoji     || '';
  const title     = tokens.title     || '(제목 없음)';
  const preheader = tokens.preheader || '';
  const reward    = tokens.reward_url || '';
  const truncTitle = title.length > 50 ? title.slice(0,50) + '…' : title;
  const truncPre   = preheader.length > 90 ? preheader.slice(0,90) + '…' : preheader;
  containerEl.innerHTML = `
    <div style="font-family: -apple-system, 'Segoe UI', sans-serif;">
      <div class="d-flex align-items-start gap-2">
        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center"
             style="width:32px;height:32px;font-size:14px;flex-shrink:0">M</div>
        <div style="min-width:0;flex:1">
          <div class="d-flex justify-content-between">
            <strong class="text-truncate" style="max-width:60%">Marketo</strong>
            <span class="text-muted small">방금</span>
          </div>
          <div class="text-truncate fw-semibold" style="font-size:13px">
            ${_esc(emoji)} ${_esc(truncTitle)}
          </div>
          <div class="text-truncate text-muted" style="font-size:12px">
            ${_esc(truncPre)}
          </div>
        </div>
      </div>
      ${reward ? `<div class="mt-2 small"><span class="text-muted">랜딩:</span>
        <a href="${_esc(reward)}" target="_blank" rel="noopener" class="text-truncate d-inline-block"
           style="max-width:100%">${_esc(reward)}</a></div>` : ''}
    </div>
  `;
}

async function loadPreviousCohort() {
  const el = document.getElementById('previous-cohort');
  if (!el) return;
  try {
    const res = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/previous-cohort`);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    const prev = (data.data && data.data.previous) || data.previous || null;
    if (!prev) {
      el.innerHTML = '<div class="text-muted">이 segment의 첫 회차입니다.</div>';
      return;
    }
    const cov   = Number(prev.coverage_pct ?? 0).toFixed(1);
    const deliv = Number(prev.delivery_rate_pct ?? 0).toFixed(1);
    el.innerHTML = `
      <dl class="row mb-0">
        <dt class="col-sm-5">발송 일시</dt><dd class="col-sm-7">${_esc((prev.send_time || '').substring(0,16))}</dd>
        <dt class="col-sm-5">캠페인</dt><dd class="col-sm-7 text-truncate" title="${_esc(prev.name)}">${_esc(prev.name || '-')}</dd>
        <dt class="col-sm-5">대상자</dt><dd class="col-sm-7">${(prev.lead_count ?? 0).toLocaleString()}명</dd>
        <dt class="col-sm-5">발송</dt><dd class="col-sm-7">${(prev.sent_count ?? 0).toLocaleString()}건</dd>
        <dt class="col-sm-5">Coverage</dt><dd class="col-sm-7"><strong>${cov}%</strong></dd>
        <dt class="col-sm-5">Delivery</dt><dd class="col-sm-7"><strong>${deliv}%</strong></dd>
        <dt class="col-sm-5">Bounce</dt><dd class="col-sm-7">${(prev.bounce_count ?? 0).toLocaleString()}건</dd>
      </dl>`;
  } catch (e) {
    el.innerHTML = `<div class="text-warning small">직전 회차 정보 실패: ${_esc(e.message)}</div>`;
  }
}

async function loadCohortTrend() {
  const el = document.getElementById('cohort-trend');
  if (!el || typeof CAMPAIGN_SEGMENT_ID === 'undefined') return;
  try {
    const res = await fetch(`${APP_URL}/api/segments/${CAMPAIGN_SEGMENT_ID}?action=cohort&limit=5`);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    const body = data.data || data;
    const campaigns = body.campaigns || [];
    if (campaigns.length === 0) {
      el.innerHTML = '<div class="text-muted">이전 회차 데이터가 없습니다.</div>';
      return;
    }
    const avg_cov   = Number(body.avg_coverage_pct ?? 0).toFixed(1);
    const avg_deliv = Number(body.avg_delivery_rate_pct ?? 0).toFixed(1);
    const trend = body.trend || 'flat';
    const arrow = trend === 'up' ? '↑' : (trend === 'down' ? '↓' : '→');
    const arrowCls = trend === 'up' ? 'text-success' : (trend === 'down' ? 'text-danger' : 'text-muted');
    let rowsHtml = '';
    campaigns.forEach(c => {
      const cov   = Number(c.coverage_pct ?? 0);
      const deliv = Number(c.delivery_rate_pct ?? 0);
      rowsHtml += `<tr>
        <td class="small text-muted">${_esc((c.send_time || '').substring(0,10))}</td>
        <td class="small text-truncate" style="max-width:160px" title="${_esc(c.name)}">${_esc(c.name || '-')}</td>
        <td class="small">${(c.lead_count ?? 0).toLocaleString()}</td>
        <td><div class="progress" style="height:10px;background:#eef"><div class="progress-bar bg-info" style="width:${Math.min(100,cov)}%"></div></div><span class="small text-muted">${cov.toFixed(1)}%</span></td>
        <td><div class="progress" style="height:10px;background:#efe"><div class="progress-bar bg-success" style="width:${Math.min(100,deliv)}%"></div></div><span class="small text-muted">${deliv.toFixed(1)}%</span></td>
      </tr>`;
    });
    el.innerHTML = `
      <div class="d-flex gap-3 mb-2 small">
        <div>평균 Coverage: <strong>${avg_cov}%</strong></div>
        <div>평균 Delivery: <strong>${avg_deliv}%</strong></div>
        <div>추세: <span class="${arrowCls} fw-bold">${arrow} ${trend}</span></div>
      </div>
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>발송일</th><th>캠페인</th><th>대상자</th><th>Coverage</th><th>Delivery</th></tr></thead>
        <tbody>${rowsHtml}</tbody>
      </table>`;
  } catch (e) {
    el.innerHTML = `<div class="text-warning small">코호트 추세 실패: ${_esc(e.message)}</div>`;
  }
}

// awaiting_approval 카드 진입 시 호출
document.addEventListener('DOMContentLoaded', () => {
  if (typeof CAMPAIGN_STATUS !== 'undefined' && CAMPAIGN_STATUS === 'awaiting_approval') {
    loadPreviousCohort();
    const previewEl = document.getElementById('inbox-preview');
    if (previewEl && typeof APPROVAL_TOKENS !== 'undefined') {
      renderStaticInboxPreview(APPROVAL_TOKENS, previewEl);
    }
  }
  if (document.getElementById('cohort-trend')) {
    loadCohortTrend();
  }
});
