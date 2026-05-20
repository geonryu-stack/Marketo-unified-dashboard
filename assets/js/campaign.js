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

loadLogs();
setInterval(loadLogs, 2000);
