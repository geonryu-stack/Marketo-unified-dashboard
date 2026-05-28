<?php
// pages/dashboard_results.php
// 발송 결과 대시보드 — 캠페인별/일별/주별/월별 뷰 + Chart.js 차트
$title = '발송 결과';
$scripts = ['dashboard-results.js'];
include __DIR__ . '/layout_header.php';
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

<h2>발송 결과 대시보드 <small class="text-muted fs-6">— 보낸 캠페인 성과 한눈에 보기</small></h2>

<!-- Filter bar -->
<div class="d-flex align-items-end gap-2 mt-3 mb-3 flex-wrap">
  <div>
    <label class="form-label small text-muted mb-1">조회 기간</label>
    <select id="weeks-select" class="form-select form-select-sm">
      <option value="1">최근 1주</option>
      <option value="2">최근 2주</option>
      <option value="4" selected>최근 4주</option>
      <option value="8">최근 8주</option>
      <option value="12">최근 12주</option>
    </select>
  </div>
  <button type="button" class="btn btn-outline-primary btn-sm" id="reload">새로고침</button>
  <span class="ms-3 small text-muted" id="last-updated"></span>
</div>

<!-- View mode tabs -->
<ul class="nav nav-tabs view-tabs mb-3">
  <li class="nav-item"><a class="nav-link active view-tab" href="#" data-view="campaigns">캠페인별</a></li>
  <li class="nav-item"><a class="nav-link view-tab" href="#" data-view="daily">일별</a></li>
  <li class="nav-item"><a class="nav-link view-tab" href="#" data-view="weekly">주별</a></li>
  <li class="nav-item"><a class="nav-link view-tab" href="#" data-view="monthly">월별</a></li>
</ul>

<!-- KPI summary cards -->
<div class="row g-3 mb-4" id="kpi-cards">
  <div class="col-auto"><div class="card h-100"><div class="card-body py-3">
    <div class="text-muted small">발송 캠페인</div>
    <div class="fs-4 fw-bold" id="t-campaigns">-</div>
  </div></div></div>
  <div class="col-auto"><div class="card h-100"><div class="card-body py-3">
    <div class="text-muted small">총 발송 수</div>
    <div class="fs-4 fw-bold" id="t-sent">-</div>
  </div></div></div>
  <div class="col-auto"><div class="card h-100"><div class="card-body py-3">
    <div class="text-muted small" data-bs-toggle="tooltip" title="추출 대상 중 실제 발송된 비율 — cap/suppress 영향 확인">
      커버리지 <span class="text-info">&#9432;</span>
    </div>
    <div class="fs-4 fw-bold" id="t-coverage">-</div>
  </div></div></div>
  <div class="col-auto"><div class="card h-100"><div class="card-body py-3">
    <div class="text-muted small" data-bs-toggle="tooltip" title="발송한 메일 중 실제 도착한 비율 — 95% 이상이면 정상">
      도달률 <span class="text-info">&#9432;</span>
    </div>
    <div class="fs-4 fw-bold" id="t-delivery">-</div>
  </div></div></div>
  <div class="col-auto"><div class="card h-100"><div class="card-body py-3">
    <div class="text-muted small" data-bs-toggle="tooltip" title="도착한 메일을 열어본 비율 — 제목·프리헤더 효과">
      오픈률 <span class="text-info">&#9432;</span>
    </div>
    <div class="fs-4 fw-bold" id="t-open">-</div>
  </div></div></div>
  <div class="col-auto"><div class="card h-100"><div class="card-body py-3">
    <div class="text-muted small" data-bs-toggle="tooltip" title="도착한 메일 중 본문 링크를 클릭한 비율 — 콘텐츠·CTA 효과">
      클릭률 <span class="text-info">&#9432;</span>
    </div>
    <div class="fs-4 fw-bold" id="t-click">-</div>
  </div></div></div>
  <div class="col-auto"><div class="card h-100"><div class="card-body py-3">
    <div class="text-muted small" data-bs-toggle="tooltip" title="수신거부율 — 0.5% 이상 지속되면 모수 피로도 검토 필요">
      수신거부 <span class="text-info">&#9432;</span>
    </div>
    <div class="fs-4 fw-bold" id="t-unsub">-</div>
  </div></div></div>
</div>

<!-- Chart area -->
<div class="card mb-4">
  <div class="card-body">
    <div class="chart-container" style="position:relative; height:350px;">
      <canvas id="main-chart"></canvas>
    </div>
  </div>
</div>

<!-- Detail section: campaigns grid -->
<div id="detail-campaigns">
  <h5>발송 회차별 성과</h5>
  <div id="campaign-grid" class="row g-3">
    <div class="col-12 text-center text-muted py-5">로딩 중...</div>
  </div>
</div>

<!-- Detail section: aggregate table -->
<div id="detail-aggregate" style="display:none;">
  <h5 id="aggregate-title">집계 데이터</h5>
  <div class="table-responsive">
    <table class="table table-sm table-hover" id="aggregate-table">
      <thead id="aggregate-thead"></thead>
      <tbody id="aggregate-tbody"></tbody>
    </table>
  </div>
</div>

<!-- 마케터 친화 가이드 -->
<div class="alert alert-info small mt-4">
  <strong>이 지표들을 어떻게 봐야 하나요?</strong>
  <ul class="mb-0 mt-1">
    <li><strong>도달률(Delivery Rate)</strong> — 보낸 메일이 실제로 받은이의 메일함에 도착한 비율이에요. <span class="text-success">95% 이상</span>이면 정상이고, 그 아래면 IT에 알려주세요.</li>
    <li><strong>오픈률(Open Rate)</strong> — 도착한 메일을 열어본 비율. 제목·프리헤더가 잘 만들어졌는지 보는 지표입니다. 같은 그룹에 보낸 직전 회차와 비교하세요.</li>
    <li><strong>클릭률(CTR)</strong> — 도착한 메일에서 본문 링크를 누른 비율. 콘텐츠와 CTA(행동 유도) 가 효과적인지 봅니다.</li>
    <li><strong>수신거부률</strong> — 이 비율이 평소보다 갑자기 올라가면 모수가 피로해진 신호. 발송 빈도나 콘텐츠 변경을 고려해요.</li>
  </ul>
  <p class="mb-0 mt-2 text-muted">
    발송 후 24시간 이내에는 도달률만 보고, 오픈률·클릭률은 발송 후 <strong>3~7일</strong>이 지나야 안정됩니다. 결과 수집이 진행 중인 캠페인은 <span class="badge bg-info">수집 중</span> 배지가 표시돼요.
  </p>
</div>

<script>const APP_URL = '<?= APP_URL ?>';</script>
<?php include __DIR__ . '/layout_footer.php'; ?>
