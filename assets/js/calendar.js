// assets/js/calendar.js — Sprint 3 ORCH 캘린더 뷰
//
// 의존: pages/calendar/index.php 가 APP_URL / CAL_INITIAL_FROM / CAL_INITIAL_TO 를 정의.
//
// 그리드: 월~일 7열 x 4행 = 28일. 좌측 사이드바에 세그먼트 목록(반복 그룹 / 단발 그룹).
// 클릭 한번으로 세그먼트 필터링, 다시 클릭하면 해제.

(function () {
  let state = {
    from: CAL_INITIAL_FROM,
    to:   CAL_INITIAL_TO,
    campaigns: [],
    segments: [],
    activeSegmentId: null, // null = 전체
  };

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // YYYY-MM-DD 문자열 + N일 → YYYY-MM-DD (로컬 타임존 무시, UTC 기준)
  // 캘린더는 분/시각 정밀도가 아니므로 UTC 일자 산술로 충분.
  function addDays(ymd, days) {
    const d = new Date(ymd + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
  }

  function statusClass(status) {
    switch (status) {
      case 'scheduled':           return 'cal-event-scheduled';
      case 'awaiting_approval':   return 'cal-event-awaiting';
      case 'sent':                return 'cal-event-sent';
      case 'needs_manual_review': return 'cal-event-needs-review';
      default:                    return 'cal-event-other';
    }
  }

  async function fetchData() {
    const res = await fetch(`${APP_URL}/api/calendar?from=${state.from}&to=${state.to}`);
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'calendar API failed');
    state.campaigns = data.data.campaigns || [];
    state.segments  = data.data.segments  || [];
  }

  function todayYmd() {
    const t = new Date();
    return t.toISOString().slice(0, 10);
  }

  function renderHeader() {
    const label = document.getElementById('cal-range-label');
    if (label) label.textContent = `(${state.from} ~ ${state.to})`;
  }

  function renderSidebar() {
    const root = document.getElementById('cal-sidebar');
    const cnt  = document.getElementById('cal-segments-count');
    if (!root) return;
    if (!state.segments.length) {
      root.innerHTML = '<div class="text-muted small p-2">세그먼트가 없습니다.</div>';
      if (cnt) cnt.textContent = '0';
      return;
    }
    // 캠페인 개수를 segment_id로 집계
    const countBySeg = {};
    state.campaigns
      .filter(c => !state.activeSegmentId || c.segment_id === state.activeSegmentId)
      .forEach(c => {
        countBySeg[c.segment_id] = (countBySeg[c.segment_id] || 0) + 1;
      });

    const recurring = state.segments.filter(s => s.is_recurring);
    const oneshot   = state.segments.filter(s => !s.is_recurring);

    const itemHtml = (s) => {
      const isActive = state.activeSegmentId === s.id ? ' active' : '';
      const cnt = countBySeg[s.id] || 0;
      return `<div class="cal-sidebar-item${isActive}" data-seg="${esc(s.id)}" title="${esc(s.name)}">
        <span class="text-truncate" style="max-width:170px">${esc(s.name)}</span>
        <span class="cal-count-badge">${cnt}</span>
      </div>`;
    };

    let html = '';
    if (state.activeSegmentId) {
      html += `<div class="cal-sidebar-group">
        <a href="#" id="cal-clear-filter" class="text-decoration-none">← 필터 해제 (전체 보기)</a>
      </div>`;
    }
    if (recurring.length) {
      html += `<div class="cal-sidebar-group">반복 발송 (${recurring.length})</div>`;
      html += recurring.map(itemHtml).join('');
    }
    if (oneshot.length) {
      html += `<div class="cal-sidebar-group">단발 (${oneshot.length})</div>`;
      html += oneshot.map(itemHtml).join('');
    }
    root.innerHTML = html;
    if (cnt) cnt.textContent = String(state.segments.length);

    root.querySelectorAll('.cal-sidebar-item').forEach(el => {
      el.addEventListener('click', () => {
        const segId = el.dataset.seg;
        state.activeSegmentId = (state.activeSegmentId === segId) ? null : segId;
        renderSidebar();
        renderGrid();
      });
    });
    const clearBtn = document.getElementById('cal-clear-filter');
    if (clearBtn) {
      clearBtn.addEventListener('click', (e) => {
        e.preventDefault();
        state.activeSegmentId = null;
        renderSidebar();
        renderGrid();
      });
    }
  }

  function renderGrid() {
    const root = document.getElementById('cal-grid');
    if (!root) return;

    // 캠페인 → 날짜 별 버킷
    const byDate = {};
    const filtered = state.activeSegmentId
      ? state.campaigns.filter(c => c.segment_id === state.activeSegmentId)
      : state.campaigns;
    filtered.forEach(c => {
      const ymd = (c.send_time || '').substring(0, 10);
      if (!ymd) return;
      (byDate[ymd] = byDate[ymd] || []).push(c);
    });

    const today = todayYmd();
    const dowHeaders = ['월', '화', '수', '목', '금', '토', '일'];
    let html = '';
    dowHeaders.forEach(h => { html += `<div class="cal-dow-header">${h}</div>`; });

    for (let i = 0; i < 28; i++) {
      const ymd = addDays(state.from, i);
      const evs = byDate[ymd] || [];
      const cls =
        ymd === today  ? 'cal-cell cal-today' :
        ymd <  today   ? 'cal-cell cal-past'  : 'cal-cell';
      const dayLabel = ymd.slice(8, 10).replace(/^0/, '');
      const dateExtra = ymd.endsWith('-01') ? ` <small class="text-muted">${ymd.slice(0,7)}</small>` : '';
      html += `<div class="${cls}" data-date="${ymd}">
        <div class="cal-date"><strong>${dayLabel}</strong>${dateExtra}</div>`;
      evs.forEach(c => {
        const time = (c.send_time || '').substring(11, 16);
        const label = time ? `${time} · ${c.name}` : c.name;
        html += `<a class="cal-event ${statusClass(c.status)}" href="${APP_URL}/campaigns/${esc(c.id)}"
                    title="${esc(c.name)} (${esc(c.status)})">${esc(label)}</a>`;
      });
      html += `</div>`;
    }
    root.innerHTML = html;
  }

  async function reload() {
    try {
      await fetchData();
      renderHeader();
      renderSidebar();
      renderGrid();
    } catch (e) {
      const root = document.getElementById('cal-grid');
      if (root) root.innerHTML = `<div class="alert alert-danger m-2">캘린더 로드 실패: ${esc(e.message)}</div>`;
    }
  }

  function navigate(deltaDays) {
    state.from = addDays(state.from, deltaDays);
    state.to   = addDays(state.from, 27);
    // URL 쿼리도 갱신 (북마크/뒤로가기 시 동일 뷰 유지)
    const url = new URL(window.location.href);
    url.searchParams.set('from', state.from);
    history.replaceState(null, '', url.toString());
    reload();
  }

  function goToThisWeek() {
    const dow = new Date().getUTCDay();           // 0(일)..6(토)
    const offset = dow === 0 ? -6 : 1 - dow;      // 월요일까지의 차이
    state.from = addDays(todayYmd(), offset);
    state.to   = addDays(state.from, 27);
    const url = new URL(window.location.href);
    url.searchParams.set('from', state.from);
    history.replaceState(null, '', url.toString());
    reload();
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('cal-prev')?.addEventListener('click',  () => navigate(-28));
    document.getElementById('cal-next')?.addEventListener('click',  () => navigate(28));
    document.getElementById('cal-today')?.addEventListener('click', goToThisWeek);
    reload();
  });
})();
