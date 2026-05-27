<?php
// pages/marketo_usage.php
// G1 — Marketo API 일별 콜 분포 시각화. /api/marketo-usage 의 JSON 데이터를 클라이언트가
// fetch 해서 progress bar + endpoint 분포 표 + 일자 네비게이션으로 표시.
$title = 'Marketo API 사용량';
include __DIR__ . '/layout_header.php';

// 운영자 편의 — 선택 가능 일자 후보 (오늘부터 14일치)
$today = date('Y-m-d');
?>
<h2>Marketo API 사용량 <small class="text-muted fs-6">— 50,000콜/일 한도 모니터링</small></h2>

<div class="d-flex align-items-end gap-2 mt-3 mb-4">
  <div>
    <label class="form-label small text-muted">조회 일자</label>
    <input type="date" id="usage-date" class="form-control form-control-sm" value="<?= $today ?>" max="<?= $today ?>">
  </div>
  <button type="button" class="btn btn-outline-secondary btn-sm" id="usage-prev">← 어제</button>
  <button type="button" class="btn btn-outline-secondary btn-sm" id="usage-today">오늘</button>
  <button type="button" class="btn btn-outline-primary btn-sm ms-2" id="usage-reload">🔄 새로고침</button>
  <span class="ms-3 small text-muted" id="usage-last-updated"></span>
</div>

<!-- 상단 요약 카드 -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-muted text-uppercase small">총 호출 수</div>
        <div class="fs-3 fw-bold mt-1" id="usage-total">-</div>
        <div class="text-muted small mt-1">대비 한도 50,000콜</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-muted text-uppercase small">한도 사용률</div>
        <div class="fs-3 fw-bold mt-1" id="usage-pct">-</div>
        <div class="progress mt-2" style="height: 8px;">
          <div class="progress-bar" id="usage-bar" role="progressbar" style="width: 0%"></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="text-muted text-uppercase small">오류 호출</div>
        <div class="fs-3 fw-bold mt-1 text-danger" id="usage-errors">-</div>
        <div class="text-muted small mt-1" id="usage-error-pct">-</div>
      </div>
    </div>
  </div>
</div>

<!-- 임계 도달 경보 -->
<div class="alert alert-warning d-none" id="usage-alert" role="alert">
  ⚠️ <strong>한도 80% 초과</strong> — Marketo 일일 50K 한도에 근접했습니다. 발송 일정 분산을 검토하세요.
</div>

<!-- endpoint별 분포 표 -->
<h5 class="mt-4">Endpoint 별 분포</h5>
<div class="table-responsive">
  <table class="table table-sm table-hover">
    <thead class="table-light">
      <tr>
        <th style="width: 32%">Endpoint</th>
        <th class="text-end" style="width: 12%">호출 수</th>
        <th class="text-end" style="width: 12%">오류 수</th>
        <th class="text-end" style="width: 12%">오류율</th>
        <th>비중</th>
      </tr>
    </thead>
    <tbody id="usage-endpoint-body">
      <tr><td colspan="5" class="text-center text-muted">로딩 중...</td></tr>
    </tbody>
  </table>
</div>

<div class="alert alert-info small mt-3" role="alert">
  📊 <strong>이 숫자는 무엇을 뜻하나요?</strong>
  <p class="mb-2 mt-1">
    마케토는 하루에 보낼 수 있는 API 호출 횟수가 <strong>5만 번</strong>으로 정해져 있습니다.
    위 표는 오늘 어떤 작업이 얼마나 많은 호출을 썼는지 보여줍니다.
    어떤 항목이 너무 많아지면 발송에 문제가 생길 수 있으니, 아래 신호가 나타나면 IT 담당자에게 알려주세요.
  </p>
  <ul class="mb-0">
    <li><strong>한도 사용률이 60%를 넘으면</strong> — 그 날 추가 발송이 위험할 수 있어요. 다음 발송 일정을 분산하는 게 좋아요.</li>
    <li><strong>한도 사용률이 80%를 넘으면</strong> — 같은 날 다른 발송이 거절될 수 있습니다. 즉시 발송 일정 검토가 필요해요.</li>
    <li>표 상단(가장 많이 쓰인 항목)에 <strong>"activities"</strong> 가 보이면 — 발송 후 결과(오픈·클릭) 를 수집하는 작업입니다. 비중이 너무 높으면 결과 수집 주기를 늘리는 걸 고려해요.</li>
    <li>표에 <strong>"lists"</strong> 로 시작하는 항목이 절반 이상이면 — 발송 대상자 명단을 매번 새로 채우는 작업이 많다는 뜻이에요. 큰 모수(예: 6만명) 그룹이 자주 발송되면 자연스럽게 늘어납니다.</li>
    <li><strong>오류 수가 0이 아니면</strong> — 일부 호출이 실패했지만 자동 재시도로 흡수됐을 가능성이 큽니다. 5% 이상이면 IT 담당자 확인 요청.</li>
  </ul>
  <p class="mb-0 mt-2 text-muted">
    💡 <strong>가장 자주 보는 신호 하나만 기억하세요</strong>: 한도 사용률 막대가 <span class="badge bg-success">초록</span>이면 안전,
    <span class="badge bg-warning">노랑</span>이면 주의, <span class="badge bg-danger">빨강</span>이면 IT 담당자에게 알리세요.
  </p>
</div>

<script>
const APP_URL = '<?= APP_URL ?>';
const TODAY   = '<?= $today ?>';

const elDate    = document.getElementById('usage-date');
const elPrev    = document.getElementById('usage-prev');
const elTodayBtn= document.getElementById('usage-today');
const elReload  = document.getElementById('usage-reload');
const elUpdated = document.getElementById('usage-last-updated');
const elTotal   = document.getElementById('usage-total');
const elPct     = document.getElementById('usage-pct');
const elBar     = document.getElementById('usage-bar');
const elErrors  = document.getElementById('usage-errors');
const elErrPct  = document.getElementById('usage-error-pct');
const elAlert   = document.getElementById('usage-alert');
const elBody    = document.getElementById('usage-endpoint-body');

function fmtNum(n) { return Number(n).toLocaleString('ko-KR'); }

function colorByPct(pct) {
  if (pct >= 80) return 'bg-danger';
  if (pct >= 50) return 'bg-warning';
  return 'bg-success';
}

async function loadUsage(date) {
  elBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">로딩 중...</td></tr>';
  try {
    const res = await fetch(APP_URL + '/api/marketo-usage?date=' + encodeURIComponent(date));
    const json = await res.json();
    if (!json.success) throw new Error(json.error || '조회 실패');
    render(json.data);
    elUpdated.textContent = '갱신: ' + new Date().toLocaleTimeString('ko-KR');
  } catch (e) {
    elBody.innerHTML = `<tr><td colspan="5" class="text-center text-danger">조회 실패: ${e.message}</td></tr>`;
  }
}

function render(d) {
  elTotal.textContent  = fmtNum(d.total);
  elPct.textContent    = d.quota_pct + '%';
  elBar.style.width    = Math.min(100, d.quota_pct) + '%';
  elBar.className      = 'progress-bar ' + colorByPct(d.quota_pct);
  elErrors.textContent = fmtNum(d.errors);
  elErrPct.textContent = d.total > 0
    ? '오류율 ' + ((d.errors / d.total) * 100).toFixed(1) + '%'
    : '-';
  elAlert.classList.toggle('d-none', !d.near_limit);

  const entries = Object.entries(d.by_endpoint || {});
  if (entries.length === 0) {
    elBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">호출 기록이 없습니다.</td></tr>';
    return;
  }
  // count 내림차순 (서버가 이미 정렬했지만 클라이언트 방어)
  entries.sort(([,a], [,b]) => b.count - a.count);
  const max = entries[0][1].count || 1;

  elBody.innerHTML = entries.map(([name, v]) => {
    const errRate = v.count > 0 ? ((v.error_count / v.count) * 100).toFixed(1) : '0.0';
    const barPct  = (v.count / max) * 100;
    return `
      <tr>
        <td><code>${name}</code></td>
        <td class="text-end">${fmtNum(v.count)}</td>
        <td class="text-end ${v.error_count > 0 ? 'text-danger' : ''}">${fmtNum(v.error_count)}</td>
        <td class="text-end">${errRate}%</td>
        <td>
          <div class="progress" style="height: 14px;">
            <div class="progress-bar bg-secondary" style="width:${barPct}%"></div>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

// 네비게이션
elDate.addEventListener('change',    () => loadUsage(elDate.value));
elReload.addEventListener('click',   () => loadUsage(elDate.value));
elTodayBtn.addEventListener('click', () => { elDate.value = TODAY; loadUsage(TODAY); });
elPrev.addEventListener('click',     () => {
  const d = new Date(elDate.value);
  d.setDate(d.getDate() - 1);
  const ymd = d.toISOString().slice(0, 10);
  elDate.value = ymd;
  loadUsage(ymd);
});

loadUsage(TODAY);
</script>

<?php include __DIR__ . '/layout_footer.php'; ?>
