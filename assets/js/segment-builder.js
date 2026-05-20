// assets/js/segment-builder.js

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
let filters = (typeof INITIAL_FILTERS !== 'undefined') ? INITIAL_FILTERS : [];

function renderFilters() {
  const container = document.getElementById('filter-rows');
  container.innerHTML = '';
  filters.forEach((f, idx) => {
    const def = FIELD_DEFS.find(d => d.field === f.field);
    const ops = OPERATORS_BY_TYPE[def?.type || 'text'];
    const noVal = NO_VALUE_OPS.includes(f.operator);

    const row = document.createElement('div');
    row.className = 'filter-row mb-2';

    // 필드 선택
    const fieldSel = document.createElement('select');
    fieldSel.className = 'form-select form-select-sm';
    fieldSel.style.width = '180px';
    FIELD_DEFS.forEach(d => {
      const opt = new Option(d.label, d.field, false, d.field === f.field);
      fieldSel.appendChild(opt);
    });
    fieldSel.onchange = () => {
      const newDef = FIELD_DEFS.find(d => d.field === fieldSel.value);
      const allowed = OPERATORS_BY_TYPE[newDef?.type || 'text'];
      filters[idx] = { field: fieldSel.value, label: newDef?.label || fieldSel.value,
                       operator: allowed.includes(filters[idx].operator) ? filters[idx].operator : '=',
                       value: '' };
      renderFilters();
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
      filters[idx].operator = opSel.value;
      renderFilters();
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
      valueEl.onchange = () => { filters[idx].value = valueEl.value; };
      row.appendChild(valueEl);
    }

    // 삭제 버튼
    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'btn btn-sm btn-outline-danger';
    delBtn.innerHTML = '🗑';
    delBtn.onclick = () => { filters.splice(idx, 1); renderFilters(); };
    row.appendChild(delBtn);

    container.appendChild(row);
  });
}

function addFilter() {
  const first = FIELD_DEFS[0];
  filters.push({ field: first.field, label: first.label, operator: '=', value: '' });
  renderFilters();
}

async function previewCount() {
  const el = document.getElementById('preview-count');
  el.textContent = '조회 중...';
  try {
    const res = await fetch(APP_URL + '/api/internal-db/preview', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ filters }),
    });
    const data = await res.json();
    el.textContent = data.success ? `${data.data.count.toLocaleString()}명 해당` : data.error;
  } catch(e) {
    el.textContent = '조회 실패';
  }
}

// 폼 제출
document.getElementById('segment-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const body = {
    name:                      form.name.value,
    description:               form.description.value,
    filters:                   filters,
    marketo_program_id:        form.marketo_program_id.value,
    marketo_audience_list_id:  form.marketo_audience_list_id.value,
    marketo_email_program_id:  form.marketo_email_program_id.value,
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

// 초기 렌더링
renderFilters();
