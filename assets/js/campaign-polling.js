// assets/js/campaign-polling.js
// 캠페인 상세 페이지 전용 — Bulk 진행, 발송 결과, 로그 폴링, 카운트다운, 코호트
// Used by: campaigns/detail.php ONLY

// ── Bulk Import 진행 카드 갱신 ──────────────────────────────
// Sprint 3 ORCH — /api/campaigns/{id}/bulk-progress 폴링하여 progress bar + rows/s + ETA 표시.
let _bulkTimer = null;

function _formatEta(sec) {
  if (sec === null || sec === undefined) return '계산 중';
  const s = Math.max(0, Math.floor(sec));
  if (s < 60)   return `약 ${s}초 남음`;
  if (s < 3600) return `약 ${Math.floor(s / 60)}분 ${s % 60}초 남음`;
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  return `약 ${h}시간 ${m}분 남음`;
}

async function loadBulkProgress() {
  const card = document.getElementById('bulk-progress-card');
  if (!card) return;
  try {
    const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/bulk-progress`);
    const data = await res.json();
    if (!data.success) return;
    const p = data.data;

    // 캠페인이 이미 bulk_polling을 벗어났으면 페이지 전체 리로드 (Complete/Failed 분기 UI로 전환).
    if (p.campaign_status && p.campaign_status !== 'bulk_polling') {
      location.reload();
      return;
    }

    const badge = document.getElementById('bulk-status-badge');
    if (badge) badge.textContent = p.status || 'Importing';

    const total     = Number(p.total)        || 0;
    const processed = Number(p.processed)    || 0;
    const pct       = Number(p.progress_pct) || 0;
    const rps       = Number(p.rows_per_sec) || 0;
    const eta       = p.eta_sec ?? null;

    const bar = document.getElementById('bulk-progress-bar');
    if (bar) {
      const pctStr = pct.toFixed(1);
      bar.style.width = `${Math.min(100, pct)}%`;
      bar.textContent = `${pctStr}%`;
      bar.setAttribute('aria-valuenow', String(pct));
    }
    const countsEl = document.getElementById('bulk-progress-counts');
    if (countsEl) {
      const failedStr = p.failed > 0 ? ` (실패 ${Number(p.failed).toLocaleString()})` : '';
      countsEl.textContent = `처리: ${processed.toLocaleString()} / ${total.toLocaleString()}${failedStr}`;
    }
    const rateEl = document.getElementById('bulk-progress-rate');
    if (rateEl) rateEl.textContent = `속도: ${rps > 0 ? rps.toFixed(1) : '-'} rows/s`;
    const etaEl = document.getElementById('bulk-progress-eta');
    if (etaEl) etaEl.textContent = `남은 시간: ${_formatEta(eta)}`;
    const updEl = document.getElementById('bulk-progress-updated');
    if (updEl) {
      const now = new Date();
      const hh = String(now.getHours()).padStart(2, '0');
      const mm = String(now.getMinutes()).padStart(2, '0');
      const ss = String(now.getSeconds()).padStart(2, '0');
      const note = p.available ? '' : ' (캐시)';
      updEl.textContent = `마지막 갱신: ${hh}:${mm}:${ss}${note}`;
    }
    if (!_bulkTimer) _bulkTimer = setInterval(loadBulkProgress, 30000);
  } catch (_) {}
}

if (typeof CAMPAIGN_STATUS !== 'undefined' && CAMPAIGN_STATUS === 'bulk_polling') {
  loadBulkProgress();
}

// ── 발송 결과 카드 갱신 ─────────────────────────────────────
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

// ── 로그 폴링 (incremental DOM 업데이트) ─────────────────────
let _lastLogCount = 0;

async function loadLogs() {
  try {
    const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/logs`);
    const data = await res.json();
    if (!data.success) return;
    const tbody = document.getElementById('log-body');
    if (!tbody) return;

    const logs = data.data;

    // Incremental: only update if log count changed
    if (logs.length !== _lastLogCount) {
      // Full rebuild only when count changes (new logs added)
      const fragment = document.createDocumentFragment();
      logs.forEach(log => {
        const cls = log.status === 'done' ? 'log-row-done' : log.status === 'error' ? 'log-row-error' : 'log-row-running';
        const tr = document.createElement('tr');
        tr.className = cls;
        tr.innerHTML = `<td>${escapeHtml(log.step)}</td><td>${escapeHtml(log.status)}</td><td>${escapeHtml(log.message || '')}</td><td>${(log.created_at || '').substring(0,16)}</td>`;
        fragment.appendChild(tr);
      });
      tbody.innerHTML = '';
      tbody.appendChild(fragment);
      _lastLogCount = logs.length;
    } else {
      // Same count: check for status changes in existing rows
      const rows = tbody.querySelectorAll('tr');
      logs.forEach((log, i) => {
        if (rows[i]) {
          const newCls = log.status === 'done' ? 'log-row-done' : log.status === 'error' ? 'log-row-error' : 'log-row-running';
          if (rows[i].className !== newCls) rows[i].className = newCls;
        }
      });
    }

    // Update count badge
    const countBadge = document.getElementById('log-count');
    if (countBadge) countBadge.textContent = logs.length;

    // Auto-expand on error or in-progress
    const hasErrors = logs.some(l => l.status === 'error');
    const collapseEl = document.getElementById('log-collapse');
    if (collapseEl && (hasErrors || ['scheduling', 'bulk_polling', 'bulk_finalizing'].includes(CAMPAIGN_STATUS))) {
      collapseEl.classList.add('show');
    }
  } catch (_) {}
}

// detail.php(CAMPAIGN_ID 정의됨)에서만 로그 폴링 활성화.
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

/** HTML 이스케이프 — utils.js의 escapeHtml() 사용. 기존 호출 호환용 alias. */
const _esc = escapeHtml;

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

// ── 직전 회차 토큰 자동 채움 (Post-S3 #3) ────────────────────────
// 새 캠페인 페이지: 세그먼트 선택 시 직전 sent 회차의 4개 토큰을 자동 채움
// (input이 비어있을 때만 — 운영자가 입력한 값을 덮어쓰지 않음).
async function loadLatestTokensForSegment(segmentId) {
  const hint = document.getElementById('latest-tokens-hint');
  if (!segmentId) {
    if (hint) hint.style.display = 'none';
    return;
  }
  try {
    const res = await fetch(`${APP_URL}/api/segments/${encodeURIComponent(segmentId)}/latest-tokens`);
    if (!res.ok) return;
    const data = await res.json();
    const latest = (data.data && data.data.latest) || null;
    if (!latest) {
      if (hint) { hint.style.display = ''; hint.textContent = '이 세그먼트의 첫 회차입니다 — 토큰을 처음부터 입력하세요.'; }
      return;
    }
    const fields = [
      { id: ['emoji', 'name=emoji'],            value: latest.emoji },
      { id: ['email-title', 'name=email_title'], value: latest.title },
      { id: ['email-preheader', 'name=email_preheader'], value: latest.preheader },
      { id: ['reward-url', 'name=reward_url'],   value: latest.reward_url },
    ];
    let filled = 0;
    fields.forEach(f => {
      // input 선택자(여러 후보)에서 첫 element 찾기
      let el = null;
      for (const sel of f.id) {
        el = document.getElementById(sel) || document.querySelector(`[${sel}]`);
        if (el) break;
      }
      if (el && !el.value && f.value) { el.value = f.value; el.dispatchEvent(new Event('input')); filled++; }
    });
    if (hint && filled > 0) {
      hint.style.display = '';
      hint.innerHTML = `<span class="text-success">✓ 직전 회차 <strong>${_esc(latest.campaign_name)}</strong> (${_esc((latest.send_time||'').substring(0,10))})의 토큰 ${filled}개를 가져왔습니다. 확인 후 수정하세요.</span>`;
    }
  } catch (e) { /* silently */ }
}

document.addEventListener('DOMContentLoaded', () => {
  const segSel = document.getElementById('segment-select');
  if (segSel) {
    segSel.addEventListener('change', () => loadLatestTokensForSegment(segSel.value));
    // 페이지 로드 시 이미 선택된 segment(예: clone에서) 있으면 호출
    if (segSel.value) loadLatestTokensForSegment(segSel.value);
  }
});

// ── G2: 캠페인 status 변화 자동 감지 (5초 주기 폴링) ─────────────
// 진행 중 상태(scheduling/bulk_*/polling)일 때만 폴링.
// status 가 바뀌면 토스트 + 페이지 상단에 새로고침 안내 배너 표시.
// 페이지 떠나거나 종료 상태(scheduled/sent/failed/done)면 자동 정리.
(function() {
  if (typeof CAMPAIGN_ID === 'undefined' || typeof CAMPAIGN_STATUS === 'undefined') return;

  const IN_PROGRESS = ['scheduling', 'bulk_polling', 'bulk_finalizing'];
  if (!IN_PROGRESS.includes(CAMPAIGN_STATUS)) return;

  let lastKnown = CAMPAIGN_STATUS;
  let timer = null;

  function showStatusChangeBanner(newStatus) {
    // 이미 배너가 있으면 중복 생성 방지
    if (document.getElementById('status-change-banner')) return;
    const div = document.createElement('div');
    div.id = 'status-change-banner';
    div.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3 shadow-lg';
    div.style.zIndex = '2000';
    div.style.minWidth = '420px';
    div.innerHTML = `
      <strong>✅ 상태 변경 감지</strong> — <code>${lastKnown}</code> → <code>${newStatus}</code>
      <button type="button" class="btn btn-sm btn-light ms-3" onclick="location.reload()">🔄 새로고침</button>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(div);
  }

  async function poll() {
    try {
      const res  = await fetch(`${APP_URL}/api/campaigns/${encodeURIComponent(CAMPAIGN_ID)}`);
      if (!res.ok) return;
      const json = await res.json();
      const cur  = json.data && json.data.status;
      if (!cur) return;
      if (cur !== lastKnown) {
        showStatusChangeBanner(cur);
        lastKnown = cur;
        // 종료 상태면 폴링 종료
        if (!IN_PROGRESS.includes(cur)) {
          clearInterval(timer);
        }
      }
    } catch (e) { /* silently */ }
  }

  timer = setInterval(poll, 5000);
  // 페이지 떠날 때 정리
  window.addEventListener('beforeunload', () => clearInterval(timer));
})();
