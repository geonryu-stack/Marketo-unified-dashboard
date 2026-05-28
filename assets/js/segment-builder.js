// assets/js/segment-builder.js
//
// Sprint 3 DB (③) 확장 — "고급 모드(OR/NOT)" 토글 추가.
//   - 기본은 v1 평면 UI (운영자 익숙). 토글 ON에서만 v2 트리 UI 노출.
//   - 폼 submit 시:
//       v1 평면 모드 → 기존과 동일하게 filters: [{field,operator,value}, ...] 전송
//       v2 고급 모드 → filters: {op:'AND'|'OR'|'NOT', children/child: [...]} 전송
//   - 서버 helpers.php::build_where_clause()가 두 입력을 자동 판별해 둘 다 처리.
//   - api/internal-db/preview, /api/segments POST/PUT 모두 filters 키를 그대로 전달하므로
//     UI 토글만 만들면 양쪽 경로가 자동 동작.

const OPERATORS_BY_TYPE = {
  text:    ['=','!=','LIKE','IN','NOT IN','IS NULL','IS NOT NULL'],
  number:  ['=','!=','>','>=','<','<=','IN','NOT IN','IS NULL','IS NOT NULL'],
  select:  ['=','!=','IN','NOT IN','IS NULL','IS NOT NULL'],
  boolean: ['=','!=','IS NULL','IS NOT NULL'],
};

const OP_LABELS = {
  '=':'같음 (=)', '!=':'다름 (≠)', '>':'초과 (>)', '>=':'이상 (≥)',
  '<':'미만 (<)', '<=':'이하 (≤)', 'IN':'포함 (IN)', 'NOT IN':'미포함 (NOT IN)',
  'LIKE':'유사 (LIKE)', 'IS NULL':'비어 있음', 'IS NOT NULL':'비어 있지 않음',
};

const NO_VALUE_OPS = ['IS NULL','IS NOT NULL'];

// ── 평면 모드(기존 v1) 상태 ───────────────────────────────────
let filters = [];

// ── 고급 모드(신규 v2) 상태 ───────────────────────────────────
// 루트는 항상 그룹 노드. NOT은 단일 자식 그룹으로 표현.
//   node 형식:
//     leaf:  { field, operator, value }
//     group: { op: 'AND'|'OR'|'NOT', children: [...] }
//   (NOT은 children[0]만 사용 — 화면도 1개로 강제)
let advancedTree = { op: 'AND', children: [] };

// 모드 플래그 — true면 v2(고급) 사용.
let advancedMode = false;

// ── 초기 상태 hydrate ────────────────────────────────────────
// 기존 세그먼트의 filters 가 평면 배열이면 → 평면 모드.
// 객체이고 'op'를 갖고 있으면 → 고급 모드(트리).
function _hydrateInitial() {
  if (typeof INITIAL_FILTERS === 'undefined') return;
  const initial = INITIAL_FILTERS;
  if (Array.isArray(initial)) {
    filters = initial.map(f => ({...f}));
    advancedMode = false;
  } else if (initial && typeof initial === 'object' && typeof initial.op === 'string') {
    advancedTree = initial;
    advancedMode = true;
  }
}

// ── v1 평면 UI 렌더 ──────────────────────────────────────────

function renderFlatFilters() {
  const container = document.getElementById('filter-rows');
  container.innerHTML = '';
  filters.forEach((f, idx) => {
    const row = _renderLeafRow(f, () => { filters.splice(idx, 1); renderAll(); }, (next) => {
      filters[idx] = next;
      renderAll();
    });
    container.appendChild(row);
  });
}

/**
 * 단일 leaf({field,operator,value}) 행을 렌더한다.
 * 평면 모드 / 고급 모드 양쪽에서 재사용.
 *   onDelete: 행 삭제 콜백
 *   onChange(nextLeaf): 값/필드/연산자 바뀔 때마다 호출
 */
function _renderLeafRow(f, onDelete, onChange) {
  const def = FIELD_DEFS.find(d => d.field === f.field) || FIELD_DEFS[0];
  const ops = OPERATORS_BY_TYPE[def?.type || 'text'];
  const noVal = NO_VALUE_OPS.includes(f.operator);

  const row = document.createElement('div');
  row.className = 'filter-row mb-2 d-flex gap-2 align-items-center flex-wrap';

  // 필드 선택
  const fieldSel = document.createElement('select');
  fieldSel.className = 'form-select form-select-sm';
  fieldSel.style.width = '180px';
  FIELD_DEFS.forEach(d => {
    const opt = new Option(d.label, d.field, false, d.field === f.field);
    fieldSel.appendChild(opt);
  });
  fieldSel.onchange = () => {
    const newDef = FIELD_DEFS.find(d => d.field === fieldSel.value) || FIELD_DEFS[0];
    const allowed = OPERATORS_BY_TYPE[newDef?.type || 'text'];
    onChange({
      field: fieldSel.value,
      label: newDef?.label || fieldSel.value,
      operator: allowed.includes(f.operator) ? f.operator : '=',
      value: ''
    });
  };

  // 연산자 선택
  const opSel = document.createElement('select');
  opSel.className = 'form-select form-select-sm';
  opSel.style.width = '160px';
  ops.forEach(op => {
    const opt = new Option(OP_LABELS[op], op, false, op === f.operator);
    opSel.appendChild(opt);
  });
  opSel.onchange = () => {
    onChange({ ...f, operator: opSel.value });
  };

  row.appendChild(fieldSel);
  row.appendChild(opSel);

  // 값 입력
  if (!noVal) {
    let valueEl;
    if (def?.type === 'boolean') {
      valueEl = document.createElement('select');
      valueEl.className = 'form-select form-select-sm';
      valueEl.style.width = '160px';
      [['', '값 선택'], ['true', '참 (true)'], ['false', '거짓 (false)']].forEach(([v, l]) => {
        valueEl.appendChild(new Option(l, v, false, v === f.value));
      });
    } else if (def?.type === 'select' && def.options) {
      valueEl = document.createElement('select');
      valueEl.className = 'form-select form-select-sm';
      valueEl.style.width = '160px';
      [['', '값 선택'], ...def.options.map(o => [o, o])].forEach(([v, l]) => {
        valueEl.appendChild(new Option(l, v, false, v === f.value));
      });
    } else {
      valueEl = document.createElement('input');
      valueEl.type = def?.type === 'number' ? 'number' : 'text';
      valueEl.className = 'form-control form-control-sm';
      valueEl.style.width = '160px';
      valueEl.placeholder = (f.operator === 'IN' || f.operator === 'NOT IN') ? 'a, b, c (콤마 구분)' : '값 입력';
      valueEl.value = f.value || '';
    }
    valueEl.onchange = () => onChange({ ...f, value: valueEl.value });
    row.appendChild(valueEl);
  }

  // 삭제
  const delBtn = document.createElement('button');
  delBtn.type = 'button';
  delBtn.className = 'btn btn-sm btn-outline-danger';
  delBtn.innerHTML = '🗑';
  delBtn.onclick = onDelete;
  row.appendChild(delBtn);

  return row;
}

// ── v2 고급 모드(트리) UI 렌더 ────────────────────────────────

function renderAdvancedTree() {
  const container = document.getElementById('filter-rows');
  container.innerHTML = '';
  container.appendChild(_renderGroupNode(advancedTree, null, null, 0));
}

/**
 * 그룹 노드(AND/OR/NOT)를 렌더한다.
 *  - AND/OR: children 다중 + (조건 추가 / 그룹 추가) 버튼
 *  - NOT   : children[0]만 사용 (단일 자식)
 *
 *  parentChildren/parentIdx — 부모의 children 배열과 본 노드의 idx (삭제용).
 *  루트 노드일 땐 null (삭제 불가).
 *  depth — 들여쓰기 표현용.
 */
function _renderGroupNode(node, parentChildren, parentIdx, depth) {
  const wrap = document.createElement('div');
  wrap.className = 'filter-group p-2 mb-2';
  wrap.style.border = '1px dashed #adb5bd';
  wrap.style.borderRadius = '6px';
  wrap.style.marginLeft = (depth * 12) + 'px';
  wrap.style.background = depth % 2 === 0 ? '#fbfbfd' : '#ffffff';

  // 헤더: op 라디오 + 삭제 버튼
  const header = document.createElement('div');
  header.className = 'd-flex gap-3 align-items-center mb-2';

  ['AND', 'OR', 'NOT'].forEach(opName => {
    const id = `op-${depth}-${Math.random().toString(36).slice(2,8)}-${opName}`;
    const r = document.createElement('input');
    r.type = 'radio';
    r.name = `group-${depth}-${parentIdx ?? 'root'}`;
    r.value = opName;
    r.id = id;
    r.checked = node.op === opName;
    r.className = 'form-check-input me-1';
    r.onchange = () => {
      node.op = opName;
      // NOT 으로 바뀔 때 children 이 2개 이상이면 첫 번째만 보존
      if (opName === 'NOT' && (node.children?.length ?? 0) > 1) {
        node.children = [node.children[0]];
      }
      renderAll();
    };
    const lbl = document.createElement('label');
    lbl.htmlFor = id;
    lbl.className = 'form-check-label small me-2';
    lbl.textContent = opName;

    header.appendChild(r);
    header.appendChild(lbl);
  });

  // 삭제 버튼(루트는 비활성)
  if (parentChildren && parentIdx !== null) {
    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'btn btn-sm btn-outline-danger ms-auto';
    del.textContent = '그룹 삭제';
    del.onclick = () => {
      parentChildren.splice(parentIdx, 1);
      renderAll();
    };
    header.appendChild(del);
  } else {
    const tag = document.createElement('span');
    tag.className = 'badge bg-secondary ms-auto';
    tag.textContent = '루트';
    header.appendChild(tag);
  }

  wrap.appendChild(header);

  // children — NOT은 항상 1개, AND/OR는 다중
  if (!Array.isArray(node.children)) node.children = [];

  node.children.forEach((child, idx) => {
    if (child && typeof child === 'object' && typeof child.op === 'string') {
      // 자식이 그룹
      wrap.appendChild(_renderGroupNode(child, node.children, idx, depth + 1));
    } else {
      // 자식이 leaf
      const onDelete = () => { node.children.splice(idx, 1); renderAll(); };
      const onChange = (nextLeaf) => { node.children[idx] = nextLeaf; renderAll(); };
      const indented = document.createElement('div');
      indented.style.marginLeft = '12px';
      indented.appendChild(_renderLeafRow(child, onDelete, onChange));
      wrap.appendChild(indented);
    }
  });

  // 액션 버튼들 — NOT 이면 자식 1개로 제한
  const actions = document.createElement('div');
  actions.className = 'd-flex gap-2 mt-1';
  actions.style.marginLeft = '12px';

  const canAddMore = node.op !== 'NOT' || node.children.length === 0;

  const addCond = document.createElement('button');
  addCond.type = 'button';
  addCond.className = 'btn btn-sm btn-outline-secondary';
  addCond.textContent = '+ 조건';
  addCond.disabled = !canAddMore;
  addCond.onclick = () => {
    const first = FIELD_DEFS[0];
    node.children.push({ field: first.field, label: first.label, operator: '=', value: '' });
    renderAll();
  };

  const addGrp = document.createElement('button');
  addGrp.type = 'button';
  addGrp.className = 'btn btn-sm btn-outline-secondary';
  addGrp.textContent = '+ 그룹';
  addGrp.disabled = !canAddMore;
  addGrp.onclick = () => {
    node.children.push({ op: 'AND', children: [] });
    renderAll();
  };

  actions.appendChild(addCond);
  actions.appendChild(addGrp);
  wrap.appendChild(actions);

  return wrap;
}

// ── 통합 렌더(모드 분기) ─────────────────────────────────────

function renderAll() {
  if (advancedMode) {
    renderAdvancedTree();
  } else {
    renderFlatFilters();
  }
  _renderAdvancedToggleState();
}

function _renderAdvancedToggleState() {
  // 평면/고급 영역 버튼 그룹 visibility 갱신
  const flatBtns = document.getElementById('flat-action-buttons');
  if (flatBtns) flatBtns.style.display = advancedMode ? 'none' : '';
}

// ── 외부 노출 함수 ───────────────────────────────────────────

function addFilter() {
  const first = FIELD_DEFS[0];
  filters.push({ field: first.field, label: first.label, operator: '=', value: '' });
  renderAll();
}

function toggleAdvancedMode(on) {
  advancedMode = !!on;
  // 모드 전환 시 자동 변환은 하지 않는다 (운영자가 의도적으로 선택).
  // 단, 빈 상태에서 켜면 첫 평면 필터를 OR 그룹으로 옮겨 가져오기 편의 제공.
  if (advancedMode && (advancedTree.children?.length ?? 0) === 0 && filters.length > 0) {
    advancedTree = { op: 'AND', children: filters.map(f => ({...f})) };
  }
  renderAll();
}

// 동의/활성 가드 토글 현재값 읽기 (체크박스가 없는 페이지에서도 기본 ON 유지)
function _consentGuardOn() {
  const cb = document.getElementById('consent-guard');
  return cb ? !!cb.checked : true;
}

// 서버로 보낼 filters 페이로드 생성 — 모드에 따라 v1/v2 분기.
function _currentFiltersPayload() {
  return advancedMode ? advancedTree : filters;
}

async function _callPreview({ withSample }) {
  const res = await fetch(APP_URL + '/api/internal-db/preview', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      filters: _currentFiltersPayload(),
      sample: !!withSample,
      consent_guard: _consentGuardOn(),
    }),
  });
  return await res.json();
}

async function previewCount() {
  const el = document.getElementById('preview-count');
  el.textContent = '조회 중...';
  try {
    const data = await _callPreview({ withSample: false });
    if (!data.success) { el.textContent = data.error; return; }
    const guardNote = data.data.consent_guard_applied ? ' (동의자만)' : ' (가드 OFF)';
    el.textContent = `${data.data.count.toLocaleString()}명 해당${guardNote}`;
  } catch(e) {
    el.textContent = '조회 실패';
  }
}

async function previewSample() {
  const countEl  = document.getElementById('preview-count');
  const tableEl  = document.getElementById('preview-sample');
  if (countEl) countEl.textContent = '조회 중...';
  if (tableEl) tableEl.innerHTML   = '';
  try {
    const data = await _callPreview({ withSample: true });
    if (!data.success) {
      if (countEl) countEl.textContent = data.error;
      return;
    }
    const guardNote = data.data.consent_guard_applied ? ' (동의자만)' : ' (가드 OFF)';
    if (countEl) countEl.textContent = `${data.data.count.toLocaleString()}명 해당${guardNote}`;

    if (!tableEl) return;
    const rows = data.data.sample || [];
    if (rows.length === 0) {
      tableEl.innerHTML = '<div class="text-muted small mt-2">표본 데이터가 없습니다.</div>';
      return;
    }
    const trs = rows.map(r => `
      <tr>
        <td><code>${r.email_masked ?? '***'}</code></td>
        <td>${r.country ?? ''}</td>
        <td>${r.days_since_login ?? ''}</td>
      </tr>
    `).join('');
    tableEl.innerHTML = `
      <div class="text-muted small mt-2">표본 ${rows.length}건 (이메일은 PII 마스킹됨)</div>
      <table class="table table-sm table-bordered mt-1" style="max-width: 520px;">
        <thead><tr>
          <th>이메일 (마스킹)</th><th>국가</th><th>마지막 로그인 경과일</th>
        </tr></thead>
        <tbody>${trs}</tbody>
      </table>
    `;
  } catch(e) {
    if (countEl) countEl.textContent = '조회 실패';
  }
}

// multi-select 의 선택된 값을 배열로 추출하는 작은 헬퍼.
// 셀렉터에 매칭되는 요소가 없거나 빈 값이면 빈 배열 반환.
function _collectSelectedValues(form, selector) {
  const el = form.querySelector(selector);
  if (!el) return [];
  return Array.from(el.selectedOptions).map(o => o.value).filter(v => v);
}

// cap 입력값 클램프 — 빈 문자열 → fallback. 음수·NaN → null (호출자가 alert).
function _clampCap(raw, fallback) {
  if (raw === undefined || raw === null || raw === '') return fallback;
  const n = Number(raw);
  if (!Number.isFinite(n) || !Number.isInteger(n) || n < 0 || n > 9999) return null;
  return n;
}

// 폼 제출
document.getElementById('segment-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const body = {
    name:                      form.name.value,
    description:               form.description.value,
    filters:                   _currentFiltersPayload(),
    marketo_program_id:        form.marketo_program_id.value,
    marketo_audience_list_id:  form.marketo_audience_list_id.value,
    marketo_email_program_id:  form.marketo_email_program_id.value,
    is_recurring:              form.is_recurring.checked ? 1 : 0,
    send_day_of_week:          parseInt(form.send_day_of_week.value),
    recurring_send_time:       form.recurring_send_time.value,
  };

  const url    = SEGMENT_ID ? APP_URL + '/api/segments/' + SEGMENT_ID : APP_URL + '/api/segments';
  const method = SEGMENT_ID ? 'PUT' : 'POST';
  const res  = await fetch(url, { method, headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
  const data = await res.json();
  if (data.success) {
    location.href = APP_URL + '/segments';
  } else {
    alert('저장 실패: ' + data.error);
  }
});

// ── 초기화 ───────────────────────────────────────────────────

_hydrateInitial();

// 고급 모드 토글 체크박스 — 페이지 측에서 #advanced-mode-toggle 를 두면 자동 연결.
document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.getElementById('advanced-mode-toggle');
  if (toggle) {
    toggle.checked = advancedMode;
    toggle.addEventListener('change', () => toggleAdvancedMode(toggle.checked));
  }
  renderAll();
});

// (DOMContentLoaded가 이미 지난 환경 대비) 즉시 1회 렌더링
renderAll();

// ── 발송 그룹 프리셋 (Post-S3 #2) ───────────────────────────────
// GET /api/groups → 드롭다운 채움. 선택 시 3개 input 자동 채움.
async function _loadGroupPresets() {
  const sel = document.getElementById('group-preset');
  if (!sel) return;
  try {
    const res = await fetch(APP_URL + '/api/groups');
    const data = await res.json();
    if (!data.success) return;
    const groups = (data.data && data.data.groups) || [];
    groups.forEach(g => {
      const opt = document.createElement('option');
      opt.value = g.id;
      opt.textContent = g.name + ' (Program ' + (g.marketo_program_id || '?') + ')';
      opt.dataset.programId       = g.marketo_program_id ?? '';
      opt.dataset.listId          = g.marketo_list_id ?? '';
      opt.dataset.emailProgramId  = g.marketo_email_program_id ?? '';
      sel.appendChild(opt);
    });
    // 현재 input 값과 일치하는 그룹이 있으면 자동 선택
    const cur = {
      p: document.getElementById('m-program-id')?.value || '',
      l: document.getElementById('m-list-id')?.value || '',
      e: document.getElementById('m-ep-id')?.value || '',
    };
    for (const o of sel.options) {
      if (o.dataset.programId == cur.p && o.dataset.listId == cur.l) {
        sel.value = o.value; break;
      }
    }
  } catch (e) { /* 그룹 API 실패는 silently — 직접 입력은 그대로 가능 */ }
}

function _applyGroupPreset(opt) {
  if (!opt) return;
  const p = document.getElementById('m-program-id');
  const l = document.getElementById('m-list-id');
  const e = document.getElementById('m-ep-id');
  if (p && opt.dataset.programId) p.value = opt.dataset.programId;
  if (l && opt.dataset.listId)    l.value = opt.dataset.listId;
  // email_program_id는 그룹에 없을 수 있음(NULL) — 비어있을 때만 자동 채움
  if (e && opt.dataset.emailProgramId && !e.value) {
    if (e.tagName === 'SELECT') {
      _setSelectValueOrAddOption(e, opt.dataset.emailProgramId, '(그룹 프리셋) #' + opt.dataset.emailProgramId);
    } else {
      e.value = opt.dataset.emailProgramId;
    }
  }
}

document.addEventListener('DOMContentLoaded', () => {
  _loadGroupPresets();
  const sel = document.getElementById('group-preset');
  if (sel) {
    sel.addEventListener('change', () => _applyGroupPreset(sel.options[sel.selectedIndex]));
  }
  const clearBtn = document.getElementById('group-preset-clear');
  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      ['m-program-id','m-list-id','m-ep-id'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (el.tagName === 'SELECT') el.value = ''; else el.value = '';
      });
      if (sel) sel.value = '';
    });
  }
});

// ── Email Program 셀렉트 (Post-S3 Option B) ───────────────────
// 이 Marketo 인스턴스는 emailPrograms/campaigns List API가 610(권한 차단)이라
// 동적 fetch 불가능. 대신 groups 테이블에 등록된 EP ID로 셀렉트를 채운다.
// 운영자가 한 번 그룹 EP ID를 SQL UPDATE 하면 이후 1클릭.
async function _loadEmailProgramOptions() {
  const sel = document.getElementById('m-ep-id');
  if (!sel || sel.tagName !== 'SELECT') return;
  const currentValue = (sel.dataset.currentValue || '').trim();

  let groups = [];
  try {
    const res  = await fetch(APP_URL + '/api/groups');
    const data = await res.json();
    if (data.success) groups = (data.data && data.data.groups) || [];
  } catch (_) {}

  sel.innerHTML = '';
  const blank = document.createElement('option');
  blank.value = '';
  blank.textContent = '— 선택 또는 직접 입력 —';
  sel.appendChild(blank);

  let matched = false;
  groups.forEach(g => {
    const ep = g.marketo_email_program_id;
    const opt = document.createElement('option');
    if (ep) {
      opt.value = String(ep);
      opt.textContent = g.name + ' (#' + ep + ')';
      if (currentValue && String(ep) === currentValue) { opt.selected = true; matched = true; }
    } else {
      // EP ID 미등록 그룹 — 비활성, 안내 목적
      opt.value = '';
      opt.textContent = g.name + ' (EP ID 미등록 — DB UPDATE 필요)';
      opt.disabled = true;
    }
    sel.appendChild(opt);
  });

  // 기존 값이 그룹 4개 외라면 보존 (수동 입력된 적 있음)
  if (currentValue && !matched) {
    const orphan = document.createElement('option');
    orphan.value = currentValue;
    orphan.textContent = '직접 입력된 ID: #' + currentValue;
    orphan.selected = true;
    sel.insertBefore(orphan, sel.children[1]);
  }

  // 마지막: "직접 입력" 옵션
  const manual = document.createElement('option');
  manual.value = '__manual__';
  manual.textContent = '+ 직접 입력 (위 그룹에 없는 EP)';
  sel.appendChild(manual);

  sel.addEventListener('change', () => {
    if (sel.value !== '__manual__') return;
    const v = (prompt('Email Program ID 입력 (Marketo UI > Email Program > URL의 숫자)') || '').trim();
    if (/^\d+$/.test(v)) {
      _setSelectValueOrAddOption(sel, v, '직접 입력된 ID: #' + v);
    } else {
      sel.value = currentValue || '';
      if (v) alert('숫자 ID만 허용됩니다.');
    }
  });
}

// _applyGroupPreset가 EP id를 자동 채울 때 셀렉트 옵션 매칭 또는 ad-hoc 추가
function _setSelectValueOrAddOption(sel, value, label) {
  if (!sel || !value) return;
  if ([...sel.options].some(o => o.value === String(value))) {
    sel.value = String(value);
    return;
  }
  const opt = document.createElement('option');
  opt.value = String(value);
  opt.textContent = label || ('(#' + value + ')');
  opt.selected = true;
  sel.appendChild(opt);
}

document.addEventListener('DOMContentLoaded', _loadEmailProgramOptions);

// ── Marketo URL 자동 파싱 (Operator Onboarding) ───────────────
async function _parseMarketoUrl() {
  const input  = document.getElementById('marketo-url-input');
  const result = document.getElementById('marketo-url-result');
  if (!input || !result) return;
  const url = input.value.trim();
  if (!url) { result.innerHTML = '<span class="text-warning">URL을 붙여넣으세요.</span>'; return; }
  try {
    const res = await fetch(APP_URL + '/api/marketo-url-parse', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ url }),
    });
    const data = await res.json();
    if (!data.success) { result.innerHTML = '<span class="text-danger">파싱 실패: ' + data.error + '</span>'; return; }
    const d = data.data;
    const targetId = { 'marketo_program_id':'m-program-id', 'marketo_audience_list_id':'m-list-id', 'marketo_email_program_id':'m-ep-id' }[d.column];
    if (targetId) {
      const target = document.getElementById(targetId);
      if (target) {
        if (target.tagName === 'SELECT') {
          _setSelectValueOrAddOption(target, d.id, d.label + ' #' + d.id);
        } else {
          target.value = d.id;
        }
        result.innerHTML = `<span class="text-success">✓ <strong>${d.label}</strong> (ID ${d.id}) → 아래 "${d.column}" 필드에 자동 입력됨</span>`;
        return;
      }
    }
    result.innerHTML = `<span class="text-info">감지: <strong>${d.label}</strong> (ID ${d.id}). 매칭되는 입력 필드 없음 — 운영 메모에만 활용하세요.</span>`;
  } catch (e) {
    result.innerHTML = '<span class="text-danger">네트워크 오류: ' + e.message + '</span>';
  }
}
document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('marketo-url-parse');
  if (btn) btn.addEventListener('click', _parseMarketoUrl);
  const input = document.getElementById('marketo-url-input');
  if (input) input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); _parseMarketoUrl(); } });
});
