// assets/js/campaign-ui.js
// 캠페인 생성/편집/상세 공통 UI — 길이 가이드, 인박스 미리보기, 콘텐츠 프리셋, URL 가드

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
 * 콘텐츠 프리셋 (v1 — JS 상수, v2부터는 fallback).
 *
 * Sprint 3 ASSET 부터:
 *  - 1차: loadContentPresets()가 /api/content-presets에서 DB 프리셋을 가져옴.
 *  - 2차(fallback): API 실패 시 이 상수를 사용.
 *
 * 드롭다운 option은 항상 (label, dataset.emoji, dataset.title, dataset.preheader)
 * 형태로 통일되도록 _populatePresetSelect()에서 정규화한다.
 */
const CONTENT_PRESETS = [
  {
    label:     '🎁 Reward Notice',
    emoji:     '🎁',
    title:     'Your reward has arrived',
    preheader: 'A limited-time benefit is waiting for you today.',
  },
  {
    label:     '⏰ Expiration Reminder',
    emoji:     '⏰',
    title:     "Don't miss out — expires at midnight",
    preheader: "You still have an unclaimed reward. Don't let it slip away.",
  },
  {
    label:     '🔥 Flash Event',
    emoji:     '🔥',
    title:     '24 hours only — special event',
    preheader: 'A weekend-exclusive reward is live right now.',
  },
  {
    label:     '✨ New Campaign Launch',
    emoji:     '✨',
    title:     'A new campaign has started',
    preheader: "We've prepared a fresh benefit tailored just for you.",
  },
  {
    label:     '💌 Weekly Digest',
    emoji:     '💌',
    title:     'Your week in review',
    preheader: 'See your activity from the past week and your next reward at a glance.',
  },
  {
    label:     '🎯 Personalized Pick',
    emoji:     '🎯',
    title:     'A reward picked just for you',
    preheader: 'Based on your recent activity — a recommendation made for you.',
  },
  {
    label:     '🎉 Celebration',
    emoji:     '🎉',
    title:     "Congrats! A new reward is unlocked",
    preheader: 'A special celebration reward, just for you.',
  },
  {
    label:     '🚀 빠른 액션 유도',
    emoji:     '🚀',
    title:     '지금 바로 시작해 보세요',
    preheader: '한 번의 클릭으로 보상을 받을 수 있어요.',
  },
];

/** HTML 이스케이프 — utils.js의 escapeHtml() 사용. 기존 호출 호환용 alias. */
const _escHtml = escapeHtml;

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
  // Sprint 3 ASSET — DB 우선, 실패 시 JS 상수 fallback.
  const presetSelect = form.querySelector('select[name="content_preset"]');
  if (presetSelect && !presetSelect.dataset.populated) {
    presetSelect.dataset.populated = '1';

    // 변경 핸들러: option.dataset에서 값 읽기 (DB/fallback 구분 불필요).
    presetSelect.addEventListener('change', (e) => {
      const opt = e.target.options[e.target.selectedIndex];
      if (!opt || !opt.value) return;
      const emoji = form.querySelector('input[name="emoji"]');
      const title = form.querySelector('input[name="email_title"]');
      const pre   = form.querySelector('input[name="email_preheader"]');
      if (emoji) emoji.value = opt.dataset.emoji     ?? '';
      if (title) title.value = opt.dataset.title     ?? '';
      if (pre)   pre.value   = opt.dataset.preheader ?? '';
      [emoji, title, pre].forEach(inp => inp?.dispatchEvent(new Event('input', { bubbles: true })));
      render();
    });

    // 초기 채움 (비동기 — render는 즉시 1회 호출)
    loadContentPresets(presetSelect);
  }

  render();
};

/**
 * /api/content-presets 에서 DB 프리셋을 가져와 select를 채운다.
 * 실패 시 CONTENT_PRESETS 상수로 fallback.
 *
 * option 정규화:
 *   value           = preset.id (DB) 또는 'fallback-N' (상수)
 *   text            = preset.label
 *   dataset.emoji     = preset.emoji
 *   dataset.title     = preset.title_template (DB) 또는 preset.title (fallback)
 *   dataset.preheader = preset.preheader_template (DB) 또는 preset.preheader (fallback)
 */
async function loadContentPresets(selectEl) {
  if (!selectEl) return;
  // 기본 placeholder 먼저 (네트워크 지연 동안에도 UI 비지 않게)
  selectEl.replaceChildren(new Option('— 프리셋을 선택하세요 —', ''));

  let presets = null;
  try {
    const url = (typeof APP_URL !== 'undefined' ? APP_URL : '') + '/api/content-presets';
    const res  = await fetch(url);
    const data = await res.json();
    if (data && data.success && Array.isArray(data.data?.presets)) {
      presets = data.data.presets.map(p => ({
        id:        p.id,
        label:     p.label,
        emoji:     p.emoji              ?? '',
        title:     p.title_template     ?? '',
        preheader: p.preheader_template ?? '',
      }));
    }
  } catch (_) {
    // 네트워크 실패 → fallback
  }

  if (!presets || presets.length === 0) {
    presets = CONTENT_PRESETS.map((p, i) => ({
      id:        `fallback-${i}`,
      label:     p.label,
      emoji:     p.emoji,
      title:     p.title,
      preheader: p.preheader,
    }));
  }

  presets.forEach(p => {
    const opt = new Option(p.label, p.id);
    opt.dataset.emoji     = p.emoji     ?? '';
    opt.dataset.title     = p.title     ?? '';
    opt.dataset.preheader = p.preheader ?? '';
    selectEl.appendChild(opt);
  });
}

// ── 프리셋 관리 모달 (Sprint 3 ASSET) ─────────────────────────
//
// new.php/edit.php의 "프리셋" select 옆 "+" 버튼이 호출. 1뷰에서:
//  - 기존 프리셋 목록 (DB) + 삭제 버튼
//  - 신규 추가 폼 (label, emoji, title, preheader)
//
// 모달이 닫힐 때 같은 페이지의 select를 재로드한다.

window.openPresetManagerModal = function (presetSelectEl) {
  // Bootstrap modal HTML 동적 생성 (페이지에 미리 두지 않아 footer-script 의존 최소화)
  let modalEl = document.getElementById('preset-manager-modal');
  if (!modalEl) {
    modalEl = document.createElement('div');
    modalEl.id = 'preset-manager-modal';
    modalEl.className = 'modal fade';
    modalEl.tabIndex = -1;
    modalEl.innerHTML = `
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">콘텐츠 프리셋 관리</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="닫기"></button>
          </div>
          <div class="modal-body">
            <h6>기존 프리셋</h6>
            <div id="preset-list" class="mb-4"><div class="text-muted small">로딩 중...</div></div>
            <hr>
            <h6>신규 프리셋 추가</h6>
            <form id="preset-new-form" class="row g-2">
              <div class="col-md-3">
                <label class="form-label small mb-1">레이블 *</label>
                <input type="text" class="form-control form-control-sm" name="label" required>
              </div>
              <div class="col-md-2">
                <label class="form-label small mb-1">이모지</label>
                <input type="text" class="form-control form-control-sm" name="emoji" maxlength="20" placeholder="🎁">
              </div>
              <div class="col-md-3">
                <label class="form-label small mb-1">제목</label>
                <input type="text" class="form-control form-control-sm" name="title_template">
              </div>
              <div class="col-md-3">
                <label class="form-label small mb-1">프리헤더</label>
                <input type="text" class="form-control form-control-sm" name="preheader_template">
              </div>
              <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-sm btn-primary w-100">추가</button>
              </div>
            </form>
            <div id="preset-error" class="text-danger small mt-2" style="display:none;"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">닫기</button>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(modalEl);
  }

  const listEl  = modalEl.querySelector('#preset-list');
  const formEl  = modalEl.querySelector('#preset-new-form');
  const errEl   = modalEl.querySelector('#preset-error');

  const showError = (msg) => {
    errEl.textContent = msg;
    errEl.style.display = msg ? 'block' : 'none';
  };

  // 목록 로드/렌더
  const reload = async () => {
    showError('');
    listEl.innerHTML = '<div class="text-muted small">로딩 중...</div>';
    try {
      const res  = await fetch(`${APP_URL}/api/content-presets`);
      const data = await res.json();
      const presets = (data?.data?.presets) || [];
      if (presets.length === 0) {
        listEl.innerHTML = '<div class="text-muted small">DB에 저장된 프리셋이 없습니다. 신규 추가 또는 fallback 상수 사용 중.</div>';
        return;
      }
      listEl.innerHTML = `
        <table class="table table-sm align-middle">
          <thead><tr><th>레이블</th><th>이모지</th><th>제목</th><th>프리헤더</th><th></th></tr></thead>
          <tbody>
            ${presets.map(p => `
              <tr data-id="${_escHtml(p.id)}">
                <td>${_escHtml(p.label || '')}</td>
                <td>${_escHtml(p.emoji || '')}</td>
                <td class="small text-muted">${_escHtml(p.title_template || '')}</td>
                <td class="small text-muted">${_escHtml(p.preheader_template || '')}</td>
                <td><button type="button" class="btn btn-sm btn-outline-danger preset-delete">삭제</button></td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
      listEl.querySelectorAll('.preset-delete').forEach(btn => {
        btn.addEventListener('click', async (e) => {
          const tr = e.target.closest('tr');
          const id = tr?.dataset.id;
          if (!id || !confirm('이 프리셋을 삭제할까요?')) return;
          try {
            const r = await fetch(`${APP_URL}/api/content-presets/${encodeURIComponent(id)}`, { method: 'DELETE' });
            if (r.status === 204 || r.ok) {
              await reload();
            } else {
              const d = await r.json().catch(() => ({}));
              showError(d.error || '삭제 실패');
            }
          } catch (_) {
            showError('네트워크 오류');
          }
        });
      });
    } catch (_) {
      listEl.innerHTML = '<div class="text-danger small">목록 로드 실패 — API 또는 DB 확인 필요.</div>';
    }
  };

  // 신규 추가 submit
  if (!formEl.dataset.bound) {
    formEl.dataset.bound = '1';
    formEl.addEventListener('submit', async (e) => {
      e.preventDefault();
      showError('');
      const body = {
        label:              formEl.label.value.trim(),
        emoji:              formEl.emoji.value.trim(),
        title_template:     formEl.title_template.value.trim(),
        preheader_template: formEl.preheader_template.value.trim(),
      };
      if (!body.label) { showError('레이블은 필수입니다.'); return; }
      try {
        const r = await fetch(`${APP_URL}/api/content-presets`, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify(body),
        });
        const d = await r.json();
        if (d.success) {
          formEl.reset();
          await reload();
        } else {
          showError(d.error || '추가 실패');
        }
      } catch (_) {
        showError('네트워크 오류');
      }
    });
  }

  // 모달이 닫힐 때 select 재로드 (DB 변경 반영)
  modalEl.addEventListener('hidden.bs.modal', () => {
    if (presetSelectEl) loadContentPresets(presetSelectEl);
  }, { once: true });

  // 모달 오픈 + 목록 1회 로드
  reload();
  if (window.bootstrap && bootstrap.Modal) {
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  } else {
    modalEl.style.display = 'block';
  }
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

// 페이지 로드 시 길이가이드/URL가드 자동 초기화
document.addEventListener('DOMContentLoaded', () => {
  initLengthGuides();
  attachRewardUrlGuard();
});
