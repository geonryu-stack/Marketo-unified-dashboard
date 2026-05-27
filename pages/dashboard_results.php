<?php
// pages/dashboard_results.php
// 발송 결과 대시보드 — 마케터(비개발자) 친화 UI.
// /api/dashboard/results 의 JSON 데이터를 받아 회차 카드 그리드 + 5대 KPI 표시.
$title = '발송 결과';
include __DIR__ . '/layout_header.php';
?>
<h2>발송 결과 대시보드 <small class="text-muted fs-6">— 보낸 캠페인 성과 한눈에 보기</small></h2>

<div class="d-flex align-items-end gap-2 mt-3 mb-4">
  <div>
    <label class="form-label small text-muted">조회 기간</label>
    <select id="weeks-select" class="form-select form-select-sm">
      <option value="1">최근 1주</option>
      <option value="2">최근 2주</option>
      <option value="4" selected>최근 4주</option>
      <option value="8">최근 8주</option>
      <option value="12">최근 12주</option>
    </select>
  </div>
  <button type="button" class="btn btn-outline-primary btn-sm" id="reload">🔄 새로고침</button>
  <span class="ms-3 small text-muted" id="last-updated"></span>
</div>

<!-- 전체 합계 요약 -->
<div class="row g-3 mb-4">
  <div class="col-md-2"><div class="card h-100"><div class="card-body py-3">
    <div class="text-muted small">발송 캠페인</div>
    <div class="fs-4 fw-bold" id="t-campaigns">-</div>
  </div></div></div>
  <div class="col-md-2"><div class="card h-100"><div class="card-body py-3">
    <div class="text-muted small">총 발송 수</div>
    <div class="fs-4 fw-bold" id="t-sent">-</div>
  </div></div></div>
  <div class="col-md-2"><div class="card h-100"><div class="card-body py-3">
    <div class="text-muted small" data-bs-toggle="tooltip" title="발송한 메일 중 실제 도착한 비율 — 95% 이상이면 정상">
      도달률 <span class="text-info">ⓘ</span>
    </div>
    <div class="fs-4 fw-bold" id="t-delivery">-</div>
  </div></div></div>
  <div class="col-md-2"><div class="card h-100"><div class="card-body py-3">
    <div class="text-muted small" data-bs-toggle="tooltip" title="도착한 메일을 열어본 비율 — 제목·프리헤더 효과">
      오픈률 <span class="text-info">ⓘ</span>
    </div>
    <div class="fs-4 fw-bold" id="t-open">-</div>
  </div></div></div>
  <div class="col-md-2"><div class="card h-100"><div class="card-body py-3">
    <div class="text-muted small" data-bs-toggle="tooltip" title="도착한 메일 중 본문 링크를 클릭한 비율 — 콘텐츠·CTA 효과">
      클릭률 <span class="text-info">ⓘ</span>
    </div>
    <div class="fs-4 fw-bold" id="t-click">-</div>
  </div></div></div>
  <div class="col-md-2"><div class="card h-100"><div class="card-body py-3">
    <div class="text-muted small" data-bs-toggle="tooltip" title="수신거부율 — 0.5% 이상 지속되면 모수 피로도 검토 필요">
      수신거부 <span class="text-info">ⓘ</span>
    </div>
    <div class="fs-4 fw-bold" id="t-unsub">-</div>
  </div></div></div>
</div>

<!-- 회차 카드 그리드 -->
<h5>발송 회차별 성과</h5>
<div id="campaign-grid" class="row g-3">
  <div class="col-12 text-center text-muted py-5">로딩 중...</div>
</div>

<!-- 마케터 친화 가이드 -->
<div class="alert alert-info small mt-4">
  📖 <strong>이 지표들을 어떻게 봐야 하나요?</strong>
  <ul class="mb-0 mt-1">
    <li><strong>도달률(Delivery Rate)</strong> — 보낸 메일이 실제로 받은이의 메일함에 도착한 비율이에요. <span class="text-success">95% 이상</span>이면 정상이고, 그 아래면 IT에 알려주세요.</li>
    <li><strong>오픈률(Open Rate)</strong> — 도착한 메일을 열어본 비율. 제목·프리헤더가 잘 만들어졌는지 보는 지표입니다. 같은 그룹에 보낸 직전 회차와 비교하세요.</li>
    <li><strong>클릭률(CTR)</strong> — 도착한 메일에서 본문 링크를 누른 비율. 콘텐츠와 CTA(행동 유도) 가 효과적인지 봅니다.</li>
    <li><strong>수신거부률</strong> — 이 비율이 평소보다 갑자기 올라가면 모수가 피로해진 신호. 발송 빈도나 콘텐츠 변경을 고려해요.</li>
  </ul>
  <p class="mb-0 mt-2 text-muted">
    💡 발송 후 24시간 이내에는 도달률만 보고, 오픈률·클릭률은 발송 후 <strong>3~7일</strong>이 지나야 안정됩니다. 결과 수집이 진행 중인 캠페인은 <span class="badge bg-info">수집 중</span> 배지가 표시돼요.
  </p>
</div>

<script>
const APP_URL = '<?= APP_URL ?>';

const elWeeks   = document.getElementById('weeks-select');
const elReload  = document.getElementById('reload');
const elUpdated = document.getElementById('last-updated');
const elGrid    = document.getElementById('campaign-grid');

function fmt(n) { return Number(n).toLocaleString('ko-KR'); }
function pct(n) { return (n == null ? '-' : n.toFixed(1) + '%'); }

// 도달률에 따른 색상 — 95%+ 초록, 80%+ 노랑, 미만 빨강
function colorRate(rate, thresh_good, thresh_warn) {
  if (rate == null || rate === 0) return 'text-muted';
  if (rate >= thresh_good) return 'text-success';
  if (rate >= thresh_warn) return 'text-warning';
  return 'text-danger';
}

async function load() {
  const weeks = elWeeks.value;
  elGrid.innerHTML = '<div class="col-12 text-center text-muted py-5">로딩 중...</div>';
  try {
    const res = await fetch(`${APP_URL}/api/dashboard/results?weeks=${weeks}`);
    const json = await res.json();
    if (!json.success) throw new Error(json.error || '조회 실패');
    render(json.data);
    elUpdated.textContent = '갱신: ' + new Date().toLocaleTimeString('ko-KR');
  } catch (e) {
    elGrid.innerHTML = `<div class="col-12 text-center text-danger py-5">조회 실패: ${e.message}</div>`;
  }
}

function render(d) {
  const t = d.totals;
  document.getElementById('t-campaigns').textContent = fmt(t.campaigns);
  document.getElementById('t-sent').textContent      = fmt(t.sent);
  document.getElementById('t-delivery').innerHTML    = `<span class="${colorRate(t.delivery_rate, 95, 80)}">${pct(t.delivery_rate)}</span>`;
  document.getElementById('t-open').textContent      = pct(t.open_rate);
  document.getElementById('t-click').textContent     = pct(t.ctr);
  // 수신거부률 동적 색상 — 0.5% 이상이면 노랑(주의), 1% 이상이면 빨강(경고)
  const unsubCls = t.unsub_rate >= 1.0 ? 'text-danger' : (t.unsub_rate >= 0.5 ? 'text-warning' : '');
  document.getElementById('t-unsub').innerHTML       = `<span class="${unsubCls}">${pct(t.unsub_rate)}</span>`;

  const list = d.campaigns;
  if (!list || list.length === 0) {
    elGrid.innerHTML = '<div class="col-12 text-center text-muted py-5">조회 기간에 발송된 캠페인이 없습니다.</div>';
    return;
  }
  elGrid.innerHTML = list.map(renderCard).join('');
  // Bootstrap tooltip 초기화
  [...document.querySelectorAll('[data-bs-toggle="tooltip"]')].forEach(el => new bootstrap.Tooltip(el));
}

function renderCard(c) {
  const polling = c.poll_status === 'polling';
  const dateStr = (c.send_time || '').substring(0, 16).replace('T', ' ');
  const statusBadge = polling
    ? '<span class="badge bg-info">📊 결과 수집 중</span>'
    : c.poll_status === 'done'    ? '<span class="badge bg-success">완료</span>'
    : c.poll_status === 'timeout' ? '<span class="badge bg-warning">수집 종료(부분)</span>'
    : '';
  return `
    <div class="col-md-6 col-xl-4">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <div class="small text-muted">${escapeHtml(c.segment_name)}</div>
              <a href="${APP_URL}/campaigns/${encodeURIComponent(c.id)}" class="fw-bold text-decoration-none">${escapeHtml(c.name)}</a>
            </div>
            ${statusBadge}
          </div>
          <div class="text-muted small mb-3">📅 ${dateStr}</div>
          <div class="row g-2 small">
            <div class="col-6"><span class="text-muted">대상자</span> ${fmt(c.lead_count)}명</div>
            <div class="col-6"><span class="text-muted">발송</span> ${fmt(c.sent)}건</div>
            <div class="col-6"><span class="text-muted">도달</span> <span class="${colorRate(c.delivery_rate, 95, 80)} fw-bold">${pct(c.delivery_rate)}</span></div>
            <div class="col-6"><span class="text-muted">오픈</span> <span class="fw-bold">${pct(c.open_rate)}</span></div>
            <div class="col-6"><span class="text-muted">클릭</span> <span class="fw-bold">${pct(c.ctr)}</span></div>
            <div class="col-6"><span class="text-muted">수신거부</span> <span class="${c.unsub_rate >= 0.5 ? 'text-warning' : ''} fw-bold">${pct(c.unsub_rate)}</span></div>
          </div>
        </div>
      </div>
    </div>
  `;
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}

elWeeks.addEventListener('change', load);
elReload.addEventListener('click', load);
load();
</script>

<?php include __DIR__ . '/layout_footer.php'; ?>
