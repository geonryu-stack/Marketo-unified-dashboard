# PHP Rewrite — Phase 2: Features

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Phase 1 Foundation 위에 세그먼트 관리, 캠페인 관리(Phase 1/2), 발송 스케줄, Cron 자동 실행을 완성하고, 기존 Next.js 파일을 제거한다.

**Architecture:** PHP 서버 렌더링 페이지 + 바닐라 JS AJAX. 캠페인 실행은 MySQL SELECT FOR UPDATE로 동시 실행 방지. Cron은 PHP CLI 스크립트를 Windows 작업 스케줄러로 1분마다 실행.

**Prerequisites:** Phase 1 완료 (Foundation 동작 확인)

**Tech Stack:** PHP 8.x, MySQL PDO, Vanilla JS fetch(), Bootstrap 5, Windows Task Scheduler

**Spec:** `docs/superpowers/specs/2026-04-24-php-rewrite-design.md`

---

## 파일 맵

| 경로 | 역할 |
|------|------|
| `pages/segments/index.php` | 세그먼트 목록 페이지 |
| `pages/segments/new.php` | 세그먼트 생성 폼 |
| `pages/segments/edit.php` | 세그먼트 편집 폼 |
| `api/segments.php` | 세그먼트 CRUD JSON API |
| `assets/js/segment-builder.js` | 필터 조건 추가/삭제 + 미리보기 |
| `pages/campaigns/index.php` | 캠페인 목록 |
| `pages/campaigns/new.php` | 캠페인 생성 폼 |
| `pages/campaigns/detail.php` | 캠페인 상세 + 실행 로그 |
| `api/campaigns.php` | 캠페인 CRUD + run/confirm/approve/reject/cancel/reset |
| `assets/js/campaign.js` | 실행 버튼 + 로그 폴링 |
| `pages/schedules/index.php` | 주간 발송 스케줄 대시보드 |
| `api/schedules.php` | 스케줄 CRUD + test/schedule |
| `assets/js/schedules.js` | 스케줄 인라인 편집 AJAX |
| `cron/run_due_campaigns.php` | Windows 작업 스케줄러 실행 대상 |

---

### Task 1: 세그먼트 API (api/segments.php)

**Files:**
- Create: `api/segments.php`

- [ ] **Step 1: api/segments.php 생성**

```php
<?php
// api/segments.php
declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'];
$params = $GLOBALS['route_params'] ?? [];
$id     = $params['id'] ?? null;

try {
    // GET /api/segments — 목록
    if ($method === 'GET' && !$id) {
        $rows = DB::all('SELECT * FROM segments ORDER BY created_at DESC');
        json_ok($rows);
    }

    // GET /api/segments/{id}
    elseif ($method === 'GET' && $id) {
        $row = DB::one('SELECT * FROM segments WHERE id = ?', [$id]);
        if (!$row) json_err('세그먼트를 찾을 수 없습니다.', 404);
        json_ok($row);
    }

    // POST /api/segments — 생성
    elseif ($method === 'POST' && !$id) {
        $body = parse_json_body();
        $now  = now_str();
        $new_id = new_uuid();
        DB::exec(
            'INSERT INTO segments
             (id, name, description, filters,
              marketo_program_id, marketo_audience_list_id, marketo_email_program_id,
              is_recurring, send_day_of_week, recurring_send_time, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $new_id,
                $body['name'] ?? '',
                $body['description'] ?? '',
                json_encode($body['filters'] ?? []),
                $body['marketo_program_id'] ?? '',
                $body['marketo_audience_list_id'] ?? '',
                $body['marketo_email_program_id'] ?? '',
                (int)($body['is_recurring'] ?? 0),
                (int)($body['send_day_of_week'] ?? 1),
                $body['recurring_send_time'] ?? '10:00',
                $now, $now,
            ]
        );
        json_ok(DB::one('SELECT * FROM segments WHERE id = ?', [$new_id]));
    }

    // PUT /api/segments/{id} — 수정
    elseif ($method === 'PUT' && $id) {
        $body = parse_json_body();
        $now  = now_str();
        DB::exec(
            'UPDATE segments SET
             name=?, description=?, filters=?,
             marketo_program_id=?, marketo_audience_list_id=?, marketo_email_program_id=?,
             is_recurring=?, send_day_of_week=?, recurring_send_time=?, updated_at=?
             WHERE id=?',
            [
                $body['name'] ?? '',
                $body['description'] ?? '',
                json_encode($body['filters'] ?? []),
                $body['marketo_program_id'] ?? '',
                $body['marketo_audience_list_id'] ?? '',
                $body['marketo_email_program_id'] ?? '',
                (int)($body['is_recurring'] ?? 0),
                (int)($body['send_day_of_week'] ?? 1),
                $body['recurring_send_time'] ?? '10:00',
                $now, $id,
            ]
        );
        json_ok(DB::one('SELECT * FROM segments WHERE id = ?', [$id]));
    }

    // DELETE /api/segments/{id}
    elseif ($method === 'DELETE' && $id) {
        DB::exec('DELETE FROM segments WHERE id = ?', [$id]);
        json_ok(null);
    }

    else {
        json_err('Not Found', 404);
    }
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}
```

- [ ] **Step 2: Commit**

```bash
git add api/segments.php
git commit -m "feat: segments CRUD API"
```

---

### Task 2: 세그먼트 페이지 + segment-builder.js

**Files:**
- Create: `pages/segments/index.php`
- Create: `pages/segments/new.php`
- Create: `pages/segments/edit.php`
- Create: `assets/js/segment-builder.js`

- [ ] **Step 1: pages/segments/index.php 생성**

```php
<?php
// pages/segments/index.php
$title   = '세그먼트';
$segments = DB::all('SELECT * FROM segments ORDER BY created_at DESC');
include __DIR__ . '/../layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2>세그먼트</h2>
  <a href="<?= APP_URL ?>/segments/new" class="btn btn-primary">+ 새 세그먼트</a>
</div>
<table class="table table-hover bg-white">
  <thead><tr>
    <th>이름</th><th>마지막 추출</th><th>대상자 수</th><th>반복 발송</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($segments as $seg): ?>
    <tr>
      <td><?= htmlspecialchars($seg['name']) ?></td>
      <td><?= $seg['last_extracted_at'] ? substr($seg['last_extracted_at'], 0, 16) : '-' ?></td>
      <td><?= $seg['last_count'] !== null ? number_format((int)$seg['last_count']) . '명' : '-' ?></td>
      <td><?= $seg['is_recurring'] ? '<span class="badge bg-success">반복</span>' : '-' ?></td>
      <td class="text-end">
        <a href="<?= APP_URL ?>/segments/<?= $seg['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">편집</a>
        <button class="btn btn-sm btn-outline-danger ms-1"
                onclick="deleteSegment('<?= $seg['id'] ?>')">삭제</button>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($segments)): ?>
    <tr><td colspan="5" class="text-center text-muted py-4">세그먼트가 없습니다.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<script>
function deleteSegment(id) {
  if (!confirm('삭제하시겠습니까?')) return;
  fetch('<?= APP_URL ?>/api/segments/' + id, { method: 'DELETE' })
    .then(r => r.json())
    .then(d => { if (d.success) location.reload(); else alert(d.error); });
}
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
```

- [ ] **Step 2: pages/segments/new.php 생성**

```php
<?php
// pages/segments/new.php
$title      = '새 세그먼트';
$field_defs = get_field_defs();
$scripts    = ['segment-builder.js'];
include __DIR__ . '/../layout_header.php';
?>
<h2>새 세그먼트</h2>
<form id="segment-form" class="mt-3">
  <div class="mb-3">
    <label class="form-label">이름 *</label>
    <input type="text" class="form-control" name="name" required>
  </div>
  <div class="mb-3">
    <label class="form-label">설명</label>
    <input type="text" class="form-control" name="description">
  </div>

  <h5 class="mt-4">필터 조건</h5>
  <div id="filter-rows"></div>
  <div class="d-flex gap-2 mt-2">
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addFilter()">+ 조건 추가</button>
    <button type="button" class="btn btn-outline-info btn-sm" onclick="previewCount()">대상자 미리보기</button>
    <span id="preview-count" class="align-self-center text-primary fw-bold"></span>
  </div>

  <h5 class="mt-4">Marketo 연결</h5>
  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <label class="form-label">Program ID</label>
      <input type="text" class="form-control" name="marketo_program_id">
    </div>
    <div class="col-md-4">
      <label class="form-label">Audience List ID</label>
      <input type="text" class="form-control" name="marketo_audience_list_id">
    </div>
    <div class="col-md-4">
      <label class="form-label">Email Program ID</label>
      <input type="text" class="form-control" name="marketo_email_program_id">
    </div>
  </div>

  <h5 class="mt-3">반복 발송</h5>
  <div class="row g-3 mb-4">
    <div class="col-auto">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="is_recurring" id="is_recurring">
        <label class="form-check-label" for="is_recurring">반복 발송 활성화</label>
      </div>
    </div>
    <div class="col-md-2">
      <select class="form-select" name="send_day_of_week">
        <?php foreach (['일','월','화','수','목','금','토'] as $i => $d): ?>
          <option value="<?= $i ?>"><?= $d ?>요일</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <input type="time" class="form-control" name="recurring_send_time" value="10:00">
    </div>
  </div>

  <button type="submit" class="btn btn-primary">저장</button>
  <a href="<?= APP_URL ?>/segments" class="btn btn-outline-secondary ms-2">취소</a>
</form>

<script>
const APP_URL  = '<?= APP_URL ?>';
const FIELD_DEFS = <?= json_encode($field_defs, JSON_UNESCAPED_UNICODE) ?>;
const SEGMENT_ID = null;
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
```

- [ ] **Step 3: pages/segments/edit.php 생성**

```php
<?php
// pages/segments/edit.php
// $id는 index.php 라우터에서 주입됨
$seg = DB::one('SELECT * FROM segments WHERE id = ?', [$id]);
if (!$seg) { header('Location: ' . APP_URL . '/segments'); exit; }

$seg['filters'] = json_decode($seg['filters'], true) ?? [];
$title          = '세그먼트 편집: ' . htmlspecialchars($seg['name']);
$field_defs     = get_field_defs();
$scripts        = ['segment-builder.js'];
include __DIR__ . '/../layout_header.php';
?>
<h2>세그먼트 편집</h2>
<form id="segment-form" class="mt-3">
  <div class="mb-3">
    <label class="form-label">이름 *</label>
    <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($seg['name']) ?>" required>
  </div>
  <div class="mb-3">
    <label class="form-label">설명</label>
    <input type="text" class="form-control" name="description" value="<?= htmlspecialchars($seg['description'] ?? '') ?>">
  </div>

  <h5 class="mt-4">필터 조건</h5>
  <div id="filter-rows"></div>
  <div class="d-flex gap-2 mt-2">
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addFilter()">+ 조건 추가</button>
    <button type="button" class="btn btn-outline-info btn-sm" onclick="previewCount()">대상자 미리보기</button>
    <span id="preview-count" class="align-self-center text-primary fw-bold"></span>
  </div>

  <h5 class="mt-4">Marketo 연결</h5>
  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <label class="form-label">Program ID</label>
      <input type="text" class="form-control" name="marketo_program_id"
             value="<?= htmlspecialchars($seg['marketo_program_id'] ?? '') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Audience List ID</label>
      <input type="text" class="form-control" name="marketo_audience_list_id"
             value="<?= htmlspecialchars($seg['marketo_audience_list_id'] ?? '') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Email Program ID</label>
      <input type="text" class="form-control" name="marketo_email_program_id"
             value="<?= htmlspecialchars($seg['marketo_email_program_id'] ?? '') ?>">
    </div>
  </div>

  <h5 class="mt-3">반복 발송</h5>
  <div class="row g-3 mb-4">
    <div class="col-auto">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="is_recurring" id="is_recurring"
               <?= $seg['is_recurring'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="is_recurring">반복 발송 활성화</label>
      </div>
    </div>
    <div class="col-md-2">
      <select class="form-select" name="send_day_of_week">
        <?php foreach (['일','월','화','수','목','금','토'] as $i => $d): ?>
          <option value="<?= $i ?>" <?= $seg['send_day_of_week'] == $i ? 'selected' : '' ?>><?= $d ?>요일</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <input type="time" class="form-control" name="recurring_send_time"
             value="<?= htmlspecialchars($seg['recurring_send_time'] ?? '10:00') ?>">
    </div>
  </div>

  <button type="submit" class="btn btn-primary">저장</button>
  <a href="<?= APP_URL ?>/segments" class="btn btn-outline-secondary ms-2">취소</a>
</form>

<script>
const APP_URL    = '<?= APP_URL ?>';
const FIELD_DEFS = <?= json_encode($field_defs, JSON_UNESCAPED_UNICODE) ?>;
const SEGMENT_ID = '<?= $seg['id'] ?>';
const INITIAL_FILTERS = <?= json_encode($seg['filters'], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
```

- [ ] **Step 4: assets/js/segment-builder.js 생성**

```javascript
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

// 초기 렌더링
renderFilters();
```

- [ ] **Step 5: 브라우저 확인**

  `http://localhost/marketo-automation/segments` → 목록 페이지 확인.
  `http://localhost/marketo-automation/segments/new` → 필터 조건 추가/삭제, 저장 후 목록 리다이렉트 확인.

- [ ] **Step 6: Commit**

```bash
git add pages/segments/ api/segments.php assets/js/segment-builder.js
git commit -m "feat: segment management pages and API"
```

---

### Task 3: 캠페인 API (api/campaigns.php)

**Files:**
- Create: `api/campaigns.php`

- [ ] **Step 1: api/campaigns.php 생성**

```php
<?php
// api/campaigns.php
declare(strict_types=1);
require_once __DIR__ . '/../src/MarketoAPI.php';
require_once __DIR__ . '/../src/InternalDB.php';

$method = $_SERVER['REQUEST_METHOD'];
$params = $GLOBALS['route_params'] ?? [];
$id     = $params['id'] ?? null;
$action = $params['action'] ?? null;

// ── 승인/거절 링크 (이메일에서 GET으로 클릭) ─────────────────
if ($id && $action === 'approve-via-link' && $method === 'GET') {
    $token      = $_GET['token'] ?? '';
    $expires_at = (int)($_GET['expires'] ?? 0);
    if (!verify_approval_token($token, 'approve', $id, $expires_at)) {
        echo '<p>링크가 만료되었거나 유효하지 않습니다.</p>'; exit;
    }
    $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
    if (!$c || $c['status'] !== 'awaiting_approval') {
        echo '<p>승인할 수 없는 상태입니다: ' . htmlspecialchars($c['status'] ?? '?') . '</p>'; exit;
    }
    DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['confirmed', now_str(), $id]);
    echo '<p>✅ 캠페인이 승인되었습니다. Phase 2 예약이 진행됩니다.</p>'; exit;
}

if ($id && $action === 'reject-via-link' && $method === 'GET') {
    $token      = $_GET['token'] ?? '';
    $expires_at = (int)($_GET['expires'] ?? 0);
    if (!verify_approval_token($token, 'reject', $id, $expires_at)) {
        echo '<p>링크가 만료되었거나 유효하지 않습니다.</p>'; exit;
    }
    DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['failed', now_str(), $id]);
    echo '<p>❌ 캠페인이 거절되었습니다.</p>'; exit;
}

try {
    // GET /api/campaigns — 목록
    if ($method === 'GET' && !$id) {
        $rows = DB::all('SELECT * FROM campaigns ORDER BY created_at DESC');
        json_ok($rows);
    }

    // GET /api/campaigns/{id}
    elseif ($method === 'GET' && $id && !$action) {
        $row = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$row) json_err('캠페인을 찾을 수 없습니다.', 404);
        json_ok($row);
    }

    // GET /api/campaigns/{id}/logs
    elseif ($method === 'GET' && $id && $action === 'logs') {
        $logs = DB::all('SELECT * FROM job_logs WHERE campaign_id=? ORDER BY created_at ASC', [$id]);
        json_ok($logs);
    }

    // POST /api/campaigns — 생성
    elseif ($method === 'POST' && !$id) {
        $body   = parse_json_body();
        $now    = now_str();
        $new_id = new_uuid();
        $seg = DB::one('SELECT name FROM segments WHERE id=?', [$body['segment_id'] ?? '']);
        DB::exec(
            'INSERT INTO campaigns
             (id, name, segment_id, segment_name, asset_name, reward_url,
              scheduled_at, send_time, status, lead_count, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,\'draft\',0,?,?)',
            [
                $new_id,
                $body['name'] ?? '',
                $body['segment_id'] ?? '',
                $seg['name'] ?? '',
                $body['asset_name'] ?? '',
                $body['reward_url'] ?? '',
                $body['scheduled_at'] ?? $now,
                $body['send_time'] ?? '10:00',
                $now, $now,
            ]
        );
        json_ok(DB::one('SELECT * FROM campaigns WHERE id=?', [$new_id]));
    }

    // POST /api/campaigns/{id}/confirm
    elseif ($method === 'POST' && $id && $action === 'confirm') {
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=? AND status=?',
            ['confirmed', now_str(), $id, 'draft']);
        json_ok(DB::one('SELECT * FROM campaigns WHERE id=?', [$id]));
    }

    // POST /api/campaigns/{id}/reset-to-draft
    elseif ($method === 'POST' && $id && $action === 'reset-to-draft') {
        $c = DB::one('SELECT status FROM campaigns WHERE id=?', [$id]);
        if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
        if ($c['status'] === 'scheduled') json_err('예약된 캠페인은 먼저 취소하세요.', 400);
        DB::exec('UPDATE campaigns SET status=?, error_message=NULL, marketo_email_program_id=NULL, updated_at=? WHERE id=?',
            ['draft', now_str(), $id]);
        json_ok(DB::one('SELECT * FROM campaigns WHERE id=?', [$id]));
    }

    // POST /api/campaigns/{id}/cancel
    elseif ($method === 'POST' && $id && $action === 'cancel') {
        $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
        if ($c['status'] !== 'scheduled') json_err('예약된 캠페인만 취소할 수 있습니다.', 400);
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['cancelling', now_str(), $id]);
        if ($c['marketo_email_program_id']) {
            MarketoAPI::unapproveEmailProgram((int)$c['marketo_email_program_id']);
        }
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['draft', now_str(), $id]);
        json_ok(['cancelled' => true]);
    }

    // POST /api/campaigns/{id}/run — Phase 1 실행
    elseif ($method === 'POST' && $id && $action === 'run') {
        run_campaign_phase1($id);
    }

    // POST /api/campaigns/{id}/approve — Phase 2 실행
    elseif ($method === 'POST' && $id && $action === 'approve') {
        run_campaign_phase2($id);
    }

    // POST /api/campaigns/{id}/reject
    elseif ($method === 'POST' && $id && $action === 'reject') {
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['failed', now_str(), $id]);
        json_ok(null);
    }

    // DELETE /api/campaigns/{id}
    elseif ($method === 'DELETE' && $id && !$action) {
        DB::exec('DELETE FROM campaigns WHERE id=?', [$id]);
        DB::exec('DELETE FROM job_logs WHERE campaign_id=?', [$id]);
        json_ok(null);
    }

    else {
        json_err('Not Found', 404);
    }

} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}

// ── Phase 1 ────────────────────────────────────────────────────

function run_campaign_phase1(string $id): void
{
    $db = DB::get();

    // CAS: extracting으로 전환 (MySQL SELECT FOR UPDATE 기반 동시 실행 방지)
    $db->beginTransaction();
    try {
        $c = DB::one('SELECT * FROM campaigns WHERE id=? FOR UPDATE', [$id]);
        if (!$c) { $db->rollBack(); json_err('캠페인을 찾을 수 없습니다.', 404); }

        // 같은 세그먼트의 진행 중 캠페인 확인
        $sibling = DB::one(
            'SELECT id, name, status FROM campaigns
             WHERE segment_id=? AND id!=?
             AND status IN (\'extracting\',\'uploading\',\'preparing\',\'awaiting_approval\',\'scheduling\',\'scheduled\',\'cancelling\')',
            [$c['segment_id'], $id]
        );
        if ($sibling) {
            $db->rollBack();
            json_err("동시 실행 차단: \"{$sibling['name']}\" 캠페인이 진행 중입니다.", 409);
        }

        $allowed = ['draft', 'confirmed', 'failed'];
        if (!in_array($c['status'], $allowed)) {
            $db->rollBack();
            json_err("실행 불가: 현재 상태 ({$c['status']})", 409);
        }

        DB::exec('UPDATE campaigns SET status=?, error_message=NULL, updated_at=? WHERE id=?',
            ['extracting', now_str(), $id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
    $seg = DB::one('SELECT * FROM segments WHERE id=?', [$c['segment_id']]);

    try {
        add_log($id, 'extract', 'running', '사내 DB 대상자 추출 시작');

        // Step 1: 대상자 추출
        $bypass = array_filter(array_map('trim', explode(',', getenv('INTERNAL_DB_BYPASS_LEADS') ?: '')));
        if (!empty($bypass)) {
            $emails = $bypass;
            add_log($id, 'extract', 'done', '[우회 모드] ' . count($emails) . '명');
        } else {
            $filters = json_decode($seg['filters'], true) ?? [];
            ['sql' => $where, 'params' => $params] = build_where_clause($filters, get_field_defs());
            $table    = INTERNAL_DB_TABLE;
            $email_f  = INTERNAL_DB_EMAIL_FIELD;
            $sql      = "SELECT `$email_f` AS email FROM `$table` WHERE $where";
            assert_readonly($sql);
            $rows   = InternalDB::query($sql, $params);
            $emails = array_column($rows, 'email');
            $emails = array_values(array_filter($emails));
            add_log($id, 'extract', 'done', '추출 완료: ' . count($emails) . '명');
        }

        DB::exec('UPDATE campaigns SET lead_count=?, updated_at=? WHERE id=?', [count($emails), now_str(), $id]);

        // Step 2: Marketo 리드 업서트
        set_campaign_status($id, 'uploading');
        add_log($id, 'upsert_leads', 'running', 'Marketo 리드 업서트 시작');
        $lead_ids = MarketoAPI::upsertLeads($emails);
        add_log($id, 'upsert_leads', 'done', count($lead_ids) . '명 업서트 완료');

        // Step 3: Static List 갱신
        $list_id = (int)$seg['marketo_audience_list_id'];
        add_log($id, 'list_refresh', 'running', "Static List($list_id) 갱신 시작");
        $existing_ids = MarketoAPI::getListLeadIds($list_id);
        if (!empty($existing_ids)) {
            MarketoAPI::removeLeadsFromList($list_id, $existing_ids);
            add_log($id, 'list_refresh', 'running', '기존 멤버 ' . count($existing_ids) . '명 제거');
        }
        MarketoAPI::addLeadsToList($list_id, $lead_ids);
        DB::exec('UPDATE campaigns SET marketo_list_id=?, marketo_list_name=?, updated_at=? WHERE id=?',
            [(string)$list_id, "Audience List $list_id", now_str(), $id]);
        add_log($id, 'list_refresh', 'done', '리스트 갱신 완료: ' . count($lead_ids) . '명 추가');

        // Step 3.5: My Token 주입
        $ep_id = (int)$seg['marketo_email_program_id'];
        if ($ep_id) {
            add_log($id, 'set_ep_tokens', 'running', "My Token EP($ep_id)에 주입 중");
            try {
                $tokens = MarketoAPI::buildEpTokenPayload(array_merge($c, $seg ? [] : []));
                if (!empty($tokens)) {
                    MarketoAPI::setProgramMyTokens($ep_id, array_values($tokens));
                    add_log($id, 'set_ep_tokens', 'done', count($tokens) . '개 토큰 설정 완료');
                }
            } catch (Throwable $te) {
                add_log($id, 'set_ep_tokens', 'error', 'My Token 설정 실패: ' . $te->getMessage());
            }
        }

        // Step 4: 테스트 메일 발송
        set_campaign_status($id, 'preparing');
        $test_emails = array_filter(array_map('trim', explode(',', SEND_TEST_EMAIL_TO)));
        if (empty($test_emails)) throw new RuntimeException('SEND_TEST_EMAIL_TO 환경 설정 누락');

        $email_id = DB::one('SELECT marketo_email_id FROM segments WHERE id=?', [$c['segment_id']]);
        // 세그먼트에 email_id 없으면 캠페인의 asset에서 가져옴 — 실제 프로젝트에서 필드 매핑 확인 필요
        // 현재 캠페인에 marketo_email_id 직접 저장 방식 채택
        $email_asset_id = (int)($c['marketo_cloned_email_id'] ?? 0);
        if (!$email_asset_id) throw new RuntimeException('Marketo Email ID가 설정되지 않았습니다.');

        add_log($id, 'send_test_email', 'running', '테스트 메일 발송: ' . implode(', ', $test_emails));
        foreach ($test_emails as $addr) {
            MarketoAPI::sendSampleEmail($email_asset_id, $addr);
        }
        add_log($id, 'send_test_email', 'done', '테스트 메일 발송 완료');

        // 승인 이메일 발송
        $expires_at  = time() + 72 * 3600;
        $approve_url = APP_URL . '/campaigns/' . $id . '/approve-via-link?token='
                     . generate_approval_token('approve', $id, $expires_at) . '&expires=' . $expires_at;
        $reject_url  = APP_URL . '/campaigns/' . $id . '/reject-via-link?token='
                     . generate_approval_token('reject',  $id, $expires_at) . '&expires=' . $expires_at;
        $fresh_c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        try { send_approval_email($fresh_c, $approve_url, $reject_url); }
        catch (Throwable $me) { add_log($id, 'send_approval_email', 'error', $me->getMessage()); }

        set_campaign_status($id, 'awaiting_approval');
        json_ok(['status' => 'awaiting_approval', 'lead_count' => count($emails)]);

    } catch (Throwable $e) {
        set_campaign_status($id, 'failed', $e->getMessage());
        add_log($id, 'error', 'error', $e->getMessage());
        json_err($e->getMessage(), 500);
    }
}

// ── Phase 2 ────────────────────────────────────────────────────

function run_campaign_phase2(string $id): void
{
    $c   = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
    $seg = DB::one('SELECT * FROM segments WHERE id=?', [$c['segment_id'] ?? '']);
    if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
    if ($c['status'] !== 'awaiting_approval') json_err('승인 대기 상태가 아닙니다.', 400);

    try {
        set_campaign_status($id, 'scheduling');
        add_log($id, 'schedule_ep', 'running', 'Email Program 예약 설정 중');

        $ep_id   = (int)($seg['marketo_email_program_id'] ?? 0);
        $send_dt = $c['send_time']
            ? date('Y-m-d', strtotime($c['scheduled_at'])) . 'T' . $c['send_time'] . ':00+0000'
            : $c['scheduled_at'];

        MarketoAPI::scheduleEmailProgram($ep_id, $send_dt);
        DB::exec('UPDATE campaigns SET marketo_email_program_id=?, updated_at=? WHERE id=?',
            [(string)$ep_id, now_str(), $id]);
        add_log($id, 'schedule_ep', 'done', "Email Program($ep_id) 예약 완료: $send_dt");
        set_campaign_status($id, 'scheduled');
        json_ok(['status' => 'scheduled']);

    } catch (Throwable $e) {
        set_campaign_status($id, 'failed', $e->getMessage());
        add_log($id, 'error', 'error', $e->getMessage());
        json_err($e->getMessage(), 500);
    }
}

// ── 공통 헬퍼 ──────────────────────────────────────────────────

function set_campaign_status(string $id, string $status, ?string $error = null): void
{
    DB::exec('UPDATE campaigns SET status=?, error_message=?, updated_at=? WHERE id=?',
        [$status, $error, now_str(), $id]);
}

function add_log(string $campaign_id, string $step, string $status, string $message): void
{
    DB::exec(
        'INSERT INTO job_logs (id, campaign_id, step, status, message, created_at) VALUES (?,?,?,?,?,?)',
        [new_uuid(), $campaign_id, $step, $status, $message, now_str()]
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add api/campaigns.php
git commit -m "feat: campaigns API — CRUD, Phase 1/2, approve/reject/cancel"
```

---

### Task 4: 캠페인 페이지 + campaign.js

**Files:**
- Create: `pages/campaigns/index.php`
- Create: `pages/campaigns/new.php`
- Create: `pages/campaigns/detail.php`
- Create: `assets/js/campaign.js`

- [ ] **Step 1: pages/campaigns/index.php 생성**

```php
<?php
// pages/campaigns/index.php
$title     = '캠페인';
$campaigns = DB::all('SELECT * FROM campaigns ORDER BY created_at DESC');
include __DIR__ . '/../layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2>캠페인</h2>
  <a href="<?= APP_URL ?>/campaigns/new" class="btn btn-primary">+ 새 캠페인</a>
</div>
<table class="table table-hover bg-white">
  <thead><tr>
    <th>이름</th><th>세그먼트</th><th>예약 시각</th><th>대상자</th><th>상태</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($campaigns as $c): ?>
    <tr>
      <td><a href="<?= APP_URL ?>/campaigns/<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a></td>
      <td><?= htmlspecialchars($c['segment_name']) ?></td>
      <td><?= substr($c['scheduled_at'], 0, 16) ?></td>
      <td><?= $c['lead_count'] > 0 ? number_format($c['lead_count']) . '명' : '-' ?></td>
      <td><span class="badge bg-<?= status_badge_class($c['status']) ?>"><?= status_label($c['status']) ?></span></td>
      <td class="text-end">
        <a href="<?= APP_URL ?>/campaigns/<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary">상세</a>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($campaigns)): ?>
    <tr><td colspan="6" class="text-center text-muted py-4">캠페인이 없습니다.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<?php include __DIR__ . '/../layout_footer.php'; ?>
```

- [ ] **Step 2: pages/campaigns/new.php 생성**

```php
<?php
// pages/campaigns/new.php
$title    = '새 캠페인';
$segments = DB::all('SELECT id, name FROM segments ORDER BY name');
include __DIR__ . '/../layout_header.php';
?>
<h2>새 캠페인</h2>
<form id="campaign-form" class="mt-3" style="max-width:600px">
  <div class="mb-3">
    <label class="form-label">캠페인 이름 *</label>
    <input type="text" class="form-control" name="name" required>
  </div>
  <div class="mb-3">
    <label class="form-label">세그먼트 *</label>
    <select class="form-select" name="segment_id" required>
      <option value="">선택하세요</option>
      <?php foreach ($segments as $s): ?>
        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="mb-3">
    <label class="form-label">이메일 이름 (에셋)</label>
    <input type="text" class="form-control" name="asset_name">
  </div>
  <div class="mb-3">
    <label class="form-label">Marketo Email ID *</label>
    <input type="number" class="form-control" name="marketo_cloned_email_id" required>
  </div>
  <div class="mb-3">
    <label class="form-label">보상 URL</label>
    <input type="url" class="form-control" name="reward_url">
  </div>
  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <label class="form-label">예약 시각 *</label>
      <input type="datetime-local" class="form-control" name="scheduled_at" required>
    </div>
    <div class="col-md-3">
      <label class="form-label">발송 시각 (RTZ)</label>
      <input type="time" class="form-control" name="send_time" value="10:00">
    </div>
  </div>
  <button type="submit" class="btn btn-primary">생성</button>
  <a href="<?= APP_URL ?>/campaigns" class="btn btn-outline-secondary ms-2">취소</a>
</form>
<script>
const APP_URL = '<?= APP_URL ?>';
document.getElementById('campaign-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  const body = {
    name: f.name.value, segment_id: f.segment_id.value,
    asset_name: f.asset_name.value, marketo_cloned_email_id: f.marketo_cloned_email_id.value,
    reward_url: f.reward_url.value,
    scheduled_at: f.scheduled_at.value.replace('T', ' '),
    send_time: f.send_time.value,
  };
  const res  = await fetch(APP_URL + '/api/campaigns', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
  const data = await res.json();
  if (data.success) location.href = APP_URL + '/campaigns/' + data.data.id;
  else alert('생성 실패: ' + data.error);
});
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
```

- [ ] **Step 3: pages/campaigns/detail.php 생성**

```php
<?php
// pages/campaigns/detail.php — $id는 router에서 주입
$c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
if (!$c) { header('Location: ' . APP_URL . '/campaigns'); exit; }
$title   = '캠페인: ' . htmlspecialchars($c['name']);
$scripts = ['campaign.js'];
include __DIR__ . '/../layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2><?= htmlspecialchars($c['name']) ?></h2>
  <span class="badge bg-<?= status_badge_class($c['status']) ?> fs-6"><?= status_label($c['status']) ?></span>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card"><div class="card-body">
      <dl class="row mb-0">
        <dt class="col-sm-5">세그먼트</dt><dd class="col-sm-7"><?= htmlspecialchars($c['segment_name']) ?></dd>
        <dt class="col-sm-5">예약 시각</dt><dd class="col-sm-7"><?= substr($c['scheduled_at'],0,16) ?></dd>
        <dt class="col-sm-5">발송 시각</dt><dd class="col-sm-7"><?= $c['send_time'] ?: '-' ?></dd>
        <dt class="col-sm-5">대상자</dt><dd class="col-sm-7"><?= $c['lead_count'] > 0 ? number_format($c['lead_count']).'명' : '-' ?></dd>
      </dl>
    </div></div>
  </div>
  <?php if ($c['error_message']): ?>
  <div class="col-12">
    <div class="alert alert-danger"><?= htmlspecialchars($c['error_message']) ?></div>
  </div>
  <?php endif; ?>
</div>

<div class="d-flex gap-2 mb-4">
  <?php if (in_array($c['status'], ['draft','confirmed'])): ?>
    <button class="btn btn-success" onclick="campaign.confirm()">Phase 1 시작</button>
  <?php endif; ?>
  <?php if ($c['status'] === 'awaiting_approval'): ?>
    <button class="btn btn-primary" onclick="campaign.approve()">Phase 2 예약</button>
    <button class="btn btn-outline-danger" onclick="campaign.reject()">거절</button>
  <?php endif; ?>
  <?php if ($c['status'] === 'scheduled'): ?>
    <button class="btn btn-outline-danger" onclick="campaign.cancel()">예약 취소</button>
  <?php endif; ?>
  <?php if (in_array($c['status'], ['failed','awaiting_approval','scheduled'])): ?>
    <button class="btn btn-outline-secondary" onclick="campaign.resetToDraft()">초안으로 되돌리기</button>
  <?php endif; ?>
  <button class="btn btn-outline-danger ms-auto" onclick="campaign.deleteCampaign()">삭제</button>
</div>

<h5>실행 로그</h5>
<table class="table table-sm bg-white" id="log-table">
  <thead><tr><th>단계</th><th>상태</th><th>메시지</th><th>시각</th></tr></thead>
  <tbody id="log-body"></tbody>
</table>

<script>
const APP_URL     = '<?= APP_URL ?>';
const CAMPAIGN_ID = '<?= $c['id'] ?>';
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
```

- [ ] **Step 4: assets/js/campaign.js 생성**

```javascript
// assets/js/campaign.js

const campaign = {
  async _action(action, method = 'POST') {
    const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/${action}`, { method });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.error || '오류가 발생했습니다.');
  },

  confirm()      { if (confirm('Phase 1을 시작하시겠습니까?')) this._run(); },
  approve()      { if (confirm('Phase 2 예약을 진행하시겠습니까?')) this._action('approve'); },
  reject()       { if (confirm('거절하시겠습니까?')) this._action('reject'); },
  cancel()       { if (confirm('예약을 취소하시겠습니까?')) this._action('cancel'); },
  resetToDraft() { if (confirm('초안으로 되돌리시겠습니까?')) this._action('reset-to-draft'); },
  deleteCampaign() {
    if (!confirm('삭제하시겠습니까? 복구할 수 없습니다.')) return;
    fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}`, { method: 'DELETE' })
      .then(() => { location.href = `${APP_URL}/campaigns`; });
  },

  async _run() {
    const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/run`, { method: 'POST' });
    const data = await res.json();
    if (!data.success) alert('실행 오류: ' + data.error);
    location.reload();
  },
};

// 로그 폴링 (2초 간격)
async function loadLogs() {
  const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/logs`);
  const data = await res.json();
  if (!data.success) return;
  const tbody = document.getElementById('log-body');
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
```

- [ ] **Step 5: 브라우저 확인**

  `http://localhost/marketo-automation/campaigns` → 목록.
  새 캠페인 생성 → 상세 페이지 → "Phase 1 시작" 버튼 → 로그 폴링 2초 간격으로 업데이트 확인.

- [ ] **Step 6: Commit**

```bash
git add pages/campaigns/ assets/js/campaign.js
git commit -m "feat: campaign management pages and log polling UI"
```

---

### Task 5: 발송 스케줄 (pages/schedules/ + api/schedules.php)

**Files:**
- Create: `api/schedules.php`
- Create: `pages/schedules/index.php`
- Create: `assets/js/schedules.js`

- [ ] **Step 1: api/schedules.php 생성**

```php
<?php
// api/schedules.php
declare(strict_types=1);
require_once __DIR__ . '/../src/MarketoAPI.php';

$method = $_SERVER['REQUEST_METHOD'];
$params = $GLOBALS['route_params'] ?? [];
$id     = $params['id'] ?? null;
$action = $params['action'] ?? null;

try {
    // GET /api/schedules?week=YYYY-MM-DD
    if ($method === 'GET' && !$id) {
        $week = $_GET['week'] ?? date('Y-m-d');
        $dates = [];
        $monday = date('Y-m-d', strtotime('monday this week', strtotime($week)));
        for ($i = 0; $i < 7; $i++) {
            $dates[] = date('Y-m-d', strtotime("+$i days", strtotime($monday)));
        }
        $groups = DB::all('SELECT * FROM groups ORDER BY sort_order');
        $schedules = DB::all(
            'SELECT * FROM send_schedules WHERE send_date BETWEEN ? AND ?',
            [$dates[0], $dates[6]]
        );
        json_ok(['groups' => $groups, 'schedules' => $schedules, 'dates' => $dates]);
    }

    // POST /api/schedules — 생성/갱신 (upsert)
    elseif ($method === 'POST' && !$id) {
        $body = parse_json_body();
        $now  = now_str();
        $existing = DB::one('SELECT id FROM send_schedules WHERE group_id=? AND send_date=?',
            [$body['group_id'], $body['send_date']]);
        if ($existing) {
            DB::exec('UPDATE send_schedules SET marketo_email_id=?, marketo_email_name=?, send_time=?, timezone=?, updated_at=? WHERE id=?',
                [$body['marketo_email_id'], $body['marketo_email_name'] ?? '', $body['send_time'] ?? '10:00', $body['timezone'] ?? 'RTZ', $now, $existing['id']]);
            json_ok(DB::one('SELECT * FROM send_schedules WHERE id=?', [$existing['id']]));
        } else {
            $new_id = new_uuid();
            DB::exec('INSERT INTO send_schedules (id,group_id,send_date,marketo_email_id,marketo_email_name,send_time,timezone,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$new_id, $body['group_id'], $body['send_date'], $body['marketo_email_id'], $body['marketo_email_name'] ?? '', $body['send_time'] ?? '10:00', $body['timezone'] ?? 'RTZ', 'draft', $now, $now]);
            json_ok(DB::one('SELECT * FROM send_schedules WHERE id=?', [$new_id]));
        }
    }

    // POST /api/schedules/{id}/test — 테스트 메일
    elseif ($method === 'POST' && $id && $action === 'test') {
        $s = DB::one('SELECT * FROM send_schedules WHERE id=?', [$id]);
        if (!$s) json_err('스케줄을 찾을 수 없습니다.', 404);
        $test_emails = array_filter(array_map('trim', explode(',', SEND_TEST_EMAIL_TO)));
        foreach ($test_emails as $email) {
            MarketoAPI::sendSampleEmail((int)$s['marketo_email_id'], $email);
        }
        DB::exec('UPDATE send_schedules SET status=?, test_sent_at=?, updated_at=? WHERE id=?',
            ['test_sent', now_str(), now_str(), $id]);
        json_ok(['sent_to' => $test_emails]);
    }

    // POST /api/schedules/{id}/schedule — Marketo 예약
    elseif ($method === 'POST' && $id && $action === 'schedule') {
        $s = DB::one('SELECT * FROM send_schedules WHERE id=?', [$id]);
        if (!$s) json_err('스케줄을 찾을 수 없습니다.', 404);
        $g = DB::one('SELECT * FROM groups WHERE id=?', [$s['group_id']]);
        $dt = $s['send_date'] . 'T' . $s['send_time'] . ':00+0000';
        MarketoAPI::scheduleEmailProgram((int)$g['marketo_campaign_id'], $dt);
        DB::exec('UPDATE send_schedules SET status=?, scheduled_at=?, updated_at=? WHERE id=?',
            ['scheduled', now_str(), now_str(), $id]);
        json_ok(['scheduled_at' => $dt]);
    }

    // DELETE /api/schedules/{id}
    elseif ($method === 'DELETE' && $id && !$action) {
        DB::exec('DELETE FROM send_schedules WHERE id=?', [$id]);
        json_ok(null);
    }

    else {
        json_err('Not Found', 404);
    }
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}
```

- [ ] **Step 2: pages/schedules/index.php 생성**

```php
<?php
// pages/schedules/index.php
$title   = '발송 스케줄';
$scripts = ['schedules.js'];
include __DIR__ . '/../layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2>발송 스케줄</h2>
  <div class="d-flex gap-2 align-items-center">
    <button class="btn btn-outline-secondary btn-sm" id="btn-prev">◀ 이전 주</button>
    <span id="week-label" class="fw-bold"></span>
    <button class="btn btn-outline-secondary btn-sm" id="btn-next">다음 주 ▶</button>
  </div>
</div>
<div id="schedule-table-wrap"></div>
<script>
const APP_URL = '<?= APP_URL ?>';
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
```

- [ ] **Step 3: assets/js/schedules.js 생성**

```javascript
// assets/js/schedules.js
const DAY_LABELS = ['일','월','화','수','목','금','토'];

let currentWeek = (() => {
  const d = new Date();
  const day = d.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  d.setDate(d.getDate() + diff);
  return d.toISOString().split('T')[0];
})();

async function loadSchedule(week) {
  const res  = await fetch(`${APP_URL}/api/schedules?week=${week}`);
  const data = await res.json();
  if (!data.success) { alert(data.error); return; }
  renderTable(data.data);
}

function renderTable({ groups, schedules, dates }) {
  const schedMap = {};
  schedules.forEach(s => { schedMap[s.group_id + '_' + s.send_date] = s; });

  document.getElementById('week-label').textContent =
    `${dates[0]} ~ ${dates[6]}`;

  let html = '<table class="table table-bordered bg-white"><thead><tr>'
           + '<th>그룹</th>'
           + dates.map(d => `<th class="text-center">${d.slice(5)}<br><small>${DAY_LABELS[new Date(d+'T00:00:00').getDay()]}</small></th>`).join('')
           + '</tr></thead><tbody>';

  groups.forEach(g => {
    html += `<tr><td class="fw-bold">${g.name}</td>`;
    dates.forEach(d => {
      const s = schedMap[g.id + '_' + d];
      html += '<td class="text-center p-1">';
      if (s) {
        html += `<div class="small fw-bold">${s.marketo_email_name || s.marketo_email_id}</div>`
              + `<div class="small text-muted">${s.send_time} (${s.timezone})</div>`
              + `<span class="badge bg-${s.status === 'scheduled' ? 'success' : s.status === 'test_sent' ? 'info' : 'secondary'}">${s.status}</span>`
              + `<div class="mt-1 d-flex gap-1 justify-content-center">`
              + `<button class="btn btn-xs btn-outline-info" onclick="testSchedule('${s.id}')">테스트</button>`
              + `<button class="btn btn-xs btn-outline-success" onclick="doSchedule('${s.id}')">예약</button>`
              + `<button class="btn btn-xs btn-outline-danger" onclick="deleteSchedule('${s.id}')">✕</button>`
              + `</div>`;
      } else {
        html += `<button class="btn btn-sm btn-outline-secondary" onclick="addSchedule('${g.id}','${d}')">+</button>`;
      }
      html += '</td>';
    });
    html += '</tr>';
  });
  html += '</tbody></table>';
  document.getElementById('schedule-table-wrap').innerHTML = html;
}

async function addSchedule(groupId, date) {
  const emailId   = prompt('Marketo Email ID:');
  if (!emailId) return;
  const emailName = prompt('이메일 이름:', '');
  const sendTime  = prompt('발송 시각 (HH:MM):', '10:00');
  const timezone  = prompt('타임존 (RTZ / KST):', 'RTZ');
  const res = await fetch(`${APP_URL}/api/schedules`, {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ group_id: groupId, send_date: date, marketo_email_id: parseInt(emailId),
                           marketo_email_name: emailName, send_time: sendTime, timezone }),
  });
  const data = await res.json();
  if (data.success) loadSchedule(currentWeek);
  else alert(data.error);
}

async function testSchedule(id) {
  const res  = await fetch(`${APP_URL}/api/schedules/${id}/test`, { method: 'POST' });
  const data = await res.json();
  alert(data.success ? '테스트 메일 발송 완료' : data.error);
  loadSchedule(currentWeek);
}

async function doSchedule(id) {
  if (!confirm('Marketo에 예약하시겠습니까?')) return;
  const res  = await fetch(`${APP_URL}/api/schedules/${id}/schedule`, { method: 'POST' });
  const data = await res.json();
  if (data.success) loadSchedule(currentWeek);
  else alert(data.error);
}

async function deleteSchedule(id) {
  if (!confirm('삭제하시겠습니까?')) return;
  await fetch(`${APP_URL}/api/schedules/${id}`, { method: 'DELETE' });
  loadSchedule(currentWeek);
}

document.getElementById('btn-prev').onclick = () => {
  currentWeek = new Date(new Date(currentWeek+'T00:00:00').getTime() - 7*86400000).toISOString().split('T')[0];
  loadSchedule(currentWeek);
};
document.getElementById('btn-next').onclick = () => {
  currentWeek = new Date(new Date(currentWeek+'T00:00:00').getTime() + 7*86400000).toISOString().split('T')[0];
  loadSchedule(currentWeek);
};

loadSchedule(currentWeek);
```

- [ ] **Step 4: 브라우저 확인**

  `http://localhost/marketo-automation/schedules` → 주간 그리드 표시, 이전/다음 주 이동 확인.

- [ ] **Step 5: Commit**

```bash
git add api/schedules.php pages/schedules/ assets/js/schedules.js
git commit -m "feat: weekly send schedule dashboard"
```

---

### Task 6: Cron 자동 실행

**Files:**
- Create: `cron/run_due_campaigns.php`

- [ ] **Step 1: cron/run_due_campaigns.php 생성**

```php
<?php
// cron/run_due_campaigns.php — PHP CLI 전용 (Windows 작업 스케줄러 실행 대상)
// 실행: php C:\xampp\htdocs\marketo-automation\cron\run_due_campaigns.php
declare(strict_types=1);

define('RUNNING_AS_CLI', true);

// CLI에서 실행되므로 경로를 절대경로로 설정
chdir(dirname(__DIR__));

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/InternalDB.php';
require_once __DIR__ . '/../src/MarketoAPI.php';
require_once __DIR__ . '/../src/helpers.php';

function cron_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

cron_log('Cron 시작');

// confirmed 상태이고 scheduled_at <= NOW() 인 캠페인 조회
$due = DB::all(
    "SELECT * FROM campaigns
     WHERE status = 'confirmed' AND scheduled_at <= ?
     ORDER BY scheduled_at ASC
     LIMIT 5",
    [now_str()]
);

if (empty($due)) {
    cron_log('실행 대상 캠페인 없음');
    exit(0);
}

foreach ($due as $c) {
    cron_log("캠페인 실행 시작: {$c['name']} (ID: {$c['id']})");
    try {
        // Phase 1 로직 직접 실행 (api/campaigns.php의 run_campaign_phase1 재사용)
        // CAS 전환
        $db = DB::get();
        $db->beginTransaction();
        $locked = DB::one('SELECT * FROM campaigns WHERE id=? FOR UPDATE', [$c['id']]);
        if (!$locked || $locked['status'] !== 'confirmed') {
            $db->rollBack();
            cron_log("  → 건너뜀: 상태 변경됨 ({$locked['status']})");
            continue;
        }
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['extracting', now_str(), $c['id']]);
        $db->commit();

        $seg = DB::one('SELECT * FROM segments WHERE id=?', [$c['segment_id']]);

        // 대상자 추출
        $filters = json_decode($seg['filters'], true) ?? [];
        ['sql' => $where, 'params' => $params] = build_where_clause($filters, get_field_defs());
        $table   = INTERNAL_DB_TABLE;
        $email_f = INTERNAL_DB_EMAIL_FIELD;
        $sql     = "SELECT `$email_f` AS email FROM `$table` WHERE $where";
        assert_readonly($sql);
        $rows   = InternalDB::query($sql, $params);
        $emails = array_values(array_filter(array_column($rows, 'email')));
        cron_log("  추출 완료: " . count($emails) . "명");

        DB::exec('UPDATE campaigns SET lead_count=?, updated_at=? WHERE id=?', [count($emails), now_str(), $c['id']]);
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['uploading', now_str(), $c['id']]);

        // 리드 업서트
        $lead_ids = MarketoAPI::upsertLeads($emails);

        // Static List 갱신
        $list_id = (int)$seg['marketo_audience_list_id'];
        $existing = MarketoAPI::getListLeadIds($list_id);
        if (!empty($existing)) MarketoAPI::removeLeadsFromList($list_id, $existing);
        MarketoAPI::addLeadsToList($list_id, $lead_ids);

        // My Token 주입
        $ep_id = (int)$seg['marketo_email_program_id'];
        if ($ep_id) {
            try {
                $tokens = MarketoAPI::buildEpTokenPayload($c);
                if (!empty($tokens)) MarketoAPI::setProgramMyTokens($ep_id, array_values($tokens));
            } catch (Throwable $te) {
                cron_log("  My Token 주입 실패 (계속): " . $te->getMessage());
            }
        }

        // 테스트 메일 발송
        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['preparing', now_str(), $c['id']]);
        $test_emails = array_filter(array_map('trim', explode(',', SEND_TEST_EMAIL_TO)));
        $email_asset_id = (int)($c['marketo_cloned_email_id'] ?? 0);
        if ($email_asset_id && !empty($test_emails)) {
            foreach ($test_emails as $addr) MarketoAPI::sendSampleEmail($email_asset_id, $addr);
        }

        // 승인 이메일
        $expires_at  = time() + 72 * 3600;
        $approve_url = APP_URL . '/campaigns/' . $c['id'] . '/approve-via-link?token='
                     . generate_approval_token('approve', $c['id'], $expires_at) . '&expires=' . $expires_at;
        $reject_url  = APP_URL . '/campaigns/' . $c['id'] . '/reject-via-link?token='
                     . generate_approval_token('reject', $c['id'], $expires_at)  . '&expires=' . $expires_at;
        $fresh = DB::one('SELECT * FROM campaigns WHERE id=?', [$c['id']]);
        send_approval_email($fresh, $approve_url, $reject_url);

        DB::exec('UPDATE campaigns SET status=?, updated_at=? WHERE id=?', ['awaiting_approval', now_str(), $c['id']]);
        cron_log("  Phase 1 완료 → awaiting_approval");

    } catch (Throwable $e) {
        DB::exec('UPDATE campaigns SET status=?, error_message=?, updated_at=? WHERE id=?',
            ['failed', $e->getMessage(), now_str(), $c['id']]);
        cron_log("  오류: " . $e->getMessage());
    }
}

cron_log('Cron 완료');
```

- [ ] **Step 2: Windows 작업 스케줄러 설정**

  1. 시작 → "작업 스케줄러" 검색 → 열기
  2. "기본 작업 만들기" 클릭
  3. 이름: `MarketoCron`
  4. 트리거: 매일 → 1분마다 반복 (고급 설정: 1분 간격, 무기한)
  5. 동작: 프로그램 시작
     - 프로그램: `C:\xampp\php\php.exe`
     - 인수: `C:\xampp\htdocs\marketo-automation\cron\run_due_campaigns.php`
  6. 저장

- [ ] **Step 3: CLI 직접 실행 테스트**

  XAMPP MySQL이 실행 중인 상태에서 CMD:
  ```cmd
  cd C:\xampp\htdocs\marketo-automation
  C:\xampp\php\php.exe cron\run_due_campaigns.php
  ```
  실행 대상 캠페인이 없으면 `실행 대상 캠페인 없음` 로그가 출력되면 정상.

- [ ] **Step 4: Commit**

```bash
git add cron/run_due_campaigns.php
git commit -m "feat: cron script for scheduled campaign auto-execution"
```

---

### Task 7: 기존 Next.js 파일 제거 및 정리

- [ ] **Step 1: Next.js 관련 파일/폴더 삭제**

```bash
# Next.js 앱 코드 제거
rm -rf app components lib db scripts node_modules .next
rm -f package.json package-lock.json tsconfig.json next.config.* postcss.config.*
rm -f tailwind.config.* eslint.config.* next-env.d.ts
```

- [ ] **Step 2: DEV_ENVIRONMENT.md 업데이트**

  `DEV_ENVIRONMENT.md` 파일에서 "결정 보류 중" 섹션을 "PHP 재작성 완료"로 업데이트:
  - "현재 스택" → `PHP 8.x + MySQL (XAMPP) + Apache`
  - "결정 보류" 섹션 삭제
  - "회사 표준 규칙 (결정 후 적용)" → "적용 중"으로 변경

- [ ] **Step 3: .gitignore 정리**

  Next.js 관련 항목 제거, PHP 관련 항목 유지:
  ```
  # PHP
  config/config.php
  config/marketo_token.cache
  
  # Misc
  .DS_Store
  ```

- [ ] **Step 4: 최종 브라우저 확인**

  - `http://localhost/marketo-automation/` → 홈 대시보드
  - `http://localhost/marketo-automation/segments` → 세그먼트 목록
  - `http://localhost/marketo-automation/campaigns` → 캠페인 목록
  - `http://localhost/marketo-automation/schedules` → 발송 스케줄 주간 그리드

- [ ] **Step 5: 최종 커밋 + 푸시**

```bash
git add -A
git commit -m "feat: PHP rewrite complete — remove Next.js, add full PHP implementation"
git push origin main
```
