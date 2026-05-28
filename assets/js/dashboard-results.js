/**
 * dashboard-results.js
 * 발송 결과 대시보드 — Chart.js 차트 + 캠페인/일별/주별/월별 뷰
 */
'use strict';

/* ── 유틸: utils.js 에서 로드 (fmt, pct, colorRate, escapeHtml) ── */

/* ── Chart.js 인스턴스 관리 ──────────────────────────── */
const chartInstances = {};
const COLORS = {
  delivery: '#198754',
  open:     '#0d6efd',
  click:    '#ffc107',
  unsub:    '#dc3545',
  bounce:   '#6c757d',
  sent:     '#0dcaf0',
};

function renderChart(canvasId, type, labels, datasets) {
  if (chartInstances[canvasId]) {
    // Reuse existing chart if same type
    const existing = chartInstances[canvasId];
    if (existing.config.type === type) {
      existing.data.labels = labels;
      existing.data.datasets = datasets;
      existing.update();
      return;
    }
    existing.destroy();
    delete chartInstances[canvasId];
  }
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;
  const ctx = canvas.getContext('2d');

  chartInstances[canvasId] = new Chart(ctx, {
    type: type,
    data: { labels: labels, datasets: datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        tooltip: {
          callbacks: {
            label: function(context) {
              var label = context.dataset.label || '';
              var val = context.parsed.y;
              if (label) label += ': ';
              if (context.dataset.yAxisID === 'y-count') {
                label += fmt(val) + '건';
              } else {
                label += val.toFixed(1) + '%';
              }
              return label;
            }
          }
        }
      },
      scales: {
        y: {
          type: 'linear',
          position: 'left',
          beginAtZero: true,
          max: 100,
          ticks: { callback: function(v) { return v + '%'; } },
          title: { display: true, text: '비율 (%)' }
        }
      }
    }
  });
}

/* ── DOM 요소 ────────────────────────────────────────── */
const elWeeks   = document.getElementById('weeks-select');
const elReload  = document.getElementById('reload');
const elUpdated = document.getElementById('last-updated');
const elGrid    = document.getElementById('campaign-grid');

let currentView = 'campaigns';

/* ── KPI 카드 갱신 ───────────────────────────────────── */
function updateKPI(totals, isCampaignView) {
  var t = totals;
  var elCampaigns = document.getElementById('t-campaigns');
  if (elCampaigns) {
    if (isCampaignView && t.campaigns != null) {
      elCampaigns.textContent = fmt(t.campaigns);
      elCampaigns.closest('.card').style.display = '';
    } else if (!isCampaignView) {
      elCampaigns.closest('.card').style.display = 'none';
    }
  }

  document.getElementById('t-sent').textContent = fmt(t.sent);

  var coverageEl = document.getElementById('t-coverage');
  if (coverageEl) {
    if (isCampaignView && t.lead_count != null && t.lead_count > 0) {
      var coverageRate = t.sent > 0 ? ((t.sent / t.lead_count) * 100).toFixed(1) : '0.0';
      coverageEl.innerHTML = '<span class="' + colorRate(parseFloat(coverageRate), 80, 50) + '">' + coverageRate + '%</span>';
      coverageEl.closest('.card').style.display = '';
    } else {
      coverageEl.closest('.card').style.display = 'none';
    }
  }

  document.getElementById('t-delivery').innerHTML =
    '<span class="' + colorRate(t.delivery_rate, 95, 80) + '">' + pct(t.delivery_rate) + '</span>';
  document.getElementById('t-open').textContent = pct(t.open_rate);
  document.getElementById('t-click').textContent = pct(t.ctr);

  var unsubCls = t.unsub_rate >= 1.0 ? 'text-danger' : (t.unsub_rate >= 0.5 ? 'text-warning' : '');
  document.getElementById('t-unsub').innerHTML = '<span class="' + unsubCls + '">' + pct(t.unsub_rate) + '</span>';
}

/* ── 뷰 전환 ─────────────────────────────────────────── */
function switchView(mode) {
  currentView = mode;
  // 탭 활성화
  document.querySelectorAll('.view-tab').forEach(function(tab) {
    if (tab.dataset.view === mode) {
      tab.classList.add('active');
    } else {
      tab.classList.remove('active');
    }
  });

  // 상세 영역 토글
  var detCampaigns = document.getElementById('detail-campaigns');
  var detAggregate = document.getElementById('detail-aggregate');
  if (mode === 'campaigns') {
    detCampaigns.style.display = '';
    detAggregate.style.display = 'none';
  } else {
    detCampaigns.style.display = 'none';
    detAggregate.style.display = '';
  }

  // 로드
  if (mode === 'campaigns') loadResults();
  else if (mode === 'daily') loadDaily();
  else if (mode === 'weekly') loadWeekly();
  else if (mode === 'monthly') loadMonthly();
}

/* ── 캠페인별 뷰 ─────────────────────────────────────── */
async function loadResults() {
  var weeks = elWeeks.value;
  elGrid.innerHTML = '<div class="col-12 text-center text-muted py-5">로딩 중...</div>';
  try {
    var res = await fetch(APP_URL + '/api/dashboard/results?weeks=' + weeks);
    var json = await res.json();
    if (!json.success) throw new Error(json.error || '조회 실패');
    var d = json.data;
    updateKPI(d.totals, true);

    var list = d.campaigns;
    if (!list || list.length === 0) {
      elGrid.innerHTML = '<div class="col-12 text-center text-muted py-5">조회 기간에 발송된 캠페인이 없습니다.</div>';
      renderChart('main-chart', 'bar', [], []);
    } else {
      elGrid.innerHTML = list.map(renderCard).join('');
      // 캠페인 비교 차트
      var labels = list.map(function(c) { return c.name.length > 20 ? c.name.substring(0, 20) + '…' : c.name; });
      renderChart('main-chart', 'bar', labels, [
        { label: '도달률', data: list.map(function(c) { return c.delivery_rate; }), backgroundColor: COLORS.delivery },
        { label: '오픈률', data: list.map(function(c) { return c.open_rate; }),     backgroundColor: COLORS.open },
        { label: '클릭률', data: list.map(function(c) { return c.ctr; }),           backgroundColor: COLORS.click },
      ]);
    }

    elUpdated.textContent = '갱신: ' + new Date().toLocaleTimeString('ko-KR');
    initTooltips();
  } catch (e) {
    elGrid.innerHTML = '<div class="col-12 text-center text-danger py-5">조회 실패: ' + escapeHtml(e.message) + '</div>';
  }
}

function renderCard(c) {
  var polling = c.poll_status === 'polling';
  var dateStr = (c.send_time || '').substring(0, 16).replace('T', ' ');
  var statusBadge = polling
    ? '<span class="badge bg-info">결과 수집 중</span>'
    : c.poll_status === 'done'    ? '<span class="badge bg-success">완료</span>'
    : c.poll_status === 'timeout' ? '<span class="badge bg-warning">수집 종료(부분)</span>'
    : '';
  return '' +
    '<div class="col-md-6 col-xl-4">' +
      '<div class="card h-100">' +
        '<div class="card-body">' +
          '<div class="d-flex justify-content-between align-items-start mb-2">' +
            '<div>' +
              '<div class="small text-muted">' + escapeHtml(c.segment_name) + '</div>' +
              '<a href="' + APP_URL + '/campaigns/' + encodeURIComponent(c.id) + '" class="fw-bold text-decoration-none">' + escapeHtml(c.name) + '</a>' +
            '</div>' +
            statusBadge +
          '</div>' +
          '<div class="text-muted small mb-3">' + dateStr + '</div>' +
          '<div class="row g-2 small">' +
            '<div class="col-6"><span class="text-muted">대상자</span> ' + fmt(c.lead_count) + '명</div>' +
            '<div class="col-6"><span class="text-muted">발송</span> ' + fmt(c.sent) + '건</div>' +
            '<div class="col-6"><span class="text-muted">도달</span> <span class="' + colorRate(c.delivery_rate, 95, 80) + ' fw-bold">' + pct(c.delivery_rate) + '</span></div>' +
            '<div class="col-6"><span class="text-muted">오픈</span> <span class="fw-bold">' + pct(c.open_rate) + '</span></div>' +
            '<div class="col-6"><span class="text-muted">클릭</span> <span class="fw-bold">' + pct(c.ctr) + '</span></div>' +
            '<div class="col-6"><span class="text-muted">수신거부</span> <span class="' + (c.unsub_rate >= 0.5 ? 'text-warning' : '') + ' fw-bold">' + pct(c.unsub_rate) + '</span></div>' +
          '</div>' +
        '</div>' +
      '</div>' +
    '</div>';
}

/* ── 일별 뷰 ─────────────────────────────────────────── */
async function loadDaily() {
  var weeks = elWeeks.value;
  try {
    var res = await fetch(APP_URL + '/api/dashboard/daily?weeks=' + weeks);
    var json = await res.json();
    if (!json.success) throw new Error(json.error || '조회 실패');
    var d = json.data;
    updateKPI(d.totals, false);

    var rows = d.rows;
    var labels = rows.map(function(r) { return r.stat_date; });
    renderChart('main-chart', 'line', labels, [
      { label: '도달률', data: rows.map(function(r) { return r.delivery_rate; }), borderColor: COLORS.delivery, backgroundColor: COLORS.delivery + '33', tension: 0.3, fill: false },
      { label: '오픈률', data: rows.map(function(r) { return r.open_rate; }),     borderColor: COLORS.open,     backgroundColor: COLORS.open + '33',     tension: 0.3, fill: false },
      { label: '클릭률', data: rows.map(function(r) { return r.ctr; }),           borderColor: COLORS.click,    backgroundColor: COLORS.click + '33',    tension: 0.3, fill: false },
    ]);

    renderDetailTable('aggregate', rows, [
      { key: 'stat_date',     label: '날짜' },
      { key: 'sent',          label: '발송',     fmt: fmt },
      { key: 'delivered',     label: '도달',     fmt: fmt },
      { key: 'delivery_rate', label: '도달률',   fmt: pct, color: function(v) { return colorRate(v, 95, 80); } },
      { key: 'open_rate',     label: '오픈률',   fmt: pct },
      { key: 'ctr',           label: '클릭률',   fmt: pct },
      { key: 'unsub_rate',    label: '수신거부', fmt: pct, color: function(v) { return v >= 0.5 ? 'text-warning' : ''; } },
    ]);
    document.getElementById('aggregate-title').textContent = '일별 집계 데이터';
    elUpdated.textContent = '갱신: ' + new Date().toLocaleTimeString('ko-KR');
  } catch (e) {
    document.getElementById('aggregate-tbody').innerHTML =
      '<tr><td colspan="99" class="text-danger text-center">조회 실패: ' + escapeHtml(e.message) + '</td></tr>';
  }
}

/* ── 주별 뷰 ─────────────────────────────────────────── */
async function loadWeekly() {
  var weeks = elWeeks.value;
  try {
    var res = await fetch(APP_URL + '/api/dashboard/weekly?weeks=' + weeks);
    var json = await res.json();
    if (!json.success) throw new Error(json.error || '조회 실패');
    var d = json.data;
    updateKPI(d.totals, false);

    var rows = d.rows;
    var labels = rows.map(function(r) { return r.label; });
    renderChart('main-chart', 'bar', labels, [
      { label: '도달률', data: rows.map(function(r) { return r.delivery_rate; }), backgroundColor: COLORS.delivery },
      { label: '오픈률', data: rows.map(function(r) { return r.open_rate; }),     backgroundColor: COLORS.open },
      { label: '클릭률', data: rows.map(function(r) { return r.ctr; }),           backgroundColor: COLORS.click },
    ]);

    renderDetailTable('aggregate', rows, [
      { key: 'label',         label: '기간' },
      { key: 'sent',          label: '발송',     fmt: fmt },
      { key: 'delivered',     label: '도달',     fmt: fmt },
      { key: 'delivery_rate', label: '도달률',   fmt: pct, color: function(v) { return colorRate(v, 95, 80); } },
      { key: 'open_rate',     label: '오픈률',   fmt: pct },
      { key: 'ctr',           label: '클릭률',   fmt: pct },
      { key: 'unsub_rate',    label: '수신거부', fmt: pct, color: function(v) { return v >= 0.5 ? 'text-warning' : ''; } },
    ]);
    document.getElementById('aggregate-title').textContent = '주별 집계 데이터';
    elUpdated.textContent = '갱신: ' + new Date().toLocaleTimeString('ko-KR');
  } catch (e) {
    document.getElementById('aggregate-tbody').innerHTML =
      '<tr><td colspan="99" class="text-danger text-center">조회 실패: ' + escapeHtml(e.message) + '</td></tr>';
  }
}

/* ── 월별 뷰 ─────────────────────────────────────────── */
async function loadMonthly() {
  var months = elWeeks.value;  // reuse weeks select; value treated as months for this view
  try {
    var res = await fetch(APP_URL + '/api/dashboard/monthly?months=' + months);
    var json = await res.json();
    if (!json.success) throw new Error(json.error || '조회 실패');
    var d = json.data;
    updateKPI(d.totals, false);

    var rows = d.rows;
    var labels = rows.map(function(r) { return r.month; });
    renderChart('main-chart', 'bar', labels, [
      { label: '도달률', data: rows.map(function(r) { return r.delivery_rate; }), backgroundColor: COLORS.delivery },
      { label: '오픈률', data: rows.map(function(r) { return r.open_rate; }),     backgroundColor: COLORS.open },
      { label: '클릭률', data: rows.map(function(r) { return r.ctr; }),           backgroundColor: COLORS.click },
    ]);

    renderDetailTable('aggregate', rows, [
      { key: 'month',         label: '월' },
      { key: 'sent',          label: '발송',     fmt: fmt },
      { key: 'delivered',     label: '도달',     fmt: fmt },
      { key: 'delivery_rate', label: '도달률',   fmt: pct, color: function(v) { return colorRate(v, 95, 80); } },
      { key: 'open_rate',     label: '오픈률',   fmt: pct },
      { key: 'ctr',           label: '클릭률',   fmt: pct },
      { key: 'unsub_rate',    label: '수신거부', fmt: pct, color: function(v) { return v >= 0.5 ? 'text-warning' : ''; } },
    ]);
    document.getElementById('aggregate-title').textContent = '월별 집계 데이터';
    elUpdated.textContent = '갱신: ' + new Date().toLocaleTimeString('ko-KR');
  } catch (e) {
    document.getElementById('aggregate-tbody').innerHTML =
      '<tr><td colspan="99" class="text-danger text-center">조회 실패: ' + escapeHtml(e.message) + '</td></tr>';
  }
}

/* ── 테이블 렌더링 ───────────────────────────────────── */
function renderDetailTable(prefix, rows, columns) {
  var thead = document.getElementById(prefix + '-thead');
  var tbody = document.getElementById(prefix + '-tbody');
  if (!thead || !tbody) return;

  thead.innerHTML = '<tr>' + columns.map(function(col) {
    return '<th>' + escapeHtml(col.label) + '</th>';
  }).join('') + '</tr>';

  if (!rows || rows.length === 0) {
    tbody.innerHTML = '<tr><td colspan="' + columns.length + '" class="text-center text-muted">데이터가 없습니다.</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(function(row) {
    return '<tr>' + columns.map(function(col) {
      var val = row[col.key];
      var display = col.fmt ? col.fmt(val) : escapeHtml(String(val));
      var cls = col.color ? col.color(val) : '';
      return '<td class="' + cls + '">' + display + '</td>';
    }).join('') + '</tr>';
  }).join('');
}

/* ── Bootstrap tooltip 초기화 ────────────────────────── */
function initTooltips() {
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
    new bootstrap.Tooltip(el);
  });
}

/* ── 이벤트 리스너 ───────────────────────────────────── */
elWeeks.addEventListener('change', function() {
  switchView(currentView);
});
elReload.addEventListener('click', function() {
  switchView(currentView);
});
document.querySelectorAll('.view-tab').forEach(function(tab) {
  tab.addEventListener('click', function(e) {
    e.preventDefault();
    switchView(this.dataset.view);
  });
});

/* ── 초기 로드 ───────────────────────────────────────── */
switchView('campaigns');
