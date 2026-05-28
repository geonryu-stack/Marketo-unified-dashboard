// assets/js/rules.js — 발송 Rule 페이지 인라인 편집 + 일괄 저장

document.addEventListener('DOMContentLoaded', () => {
  const table   = document.getElementById('rules-table');
  const saveBtn = document.getElementById('btn-save-all');
  if (!table || !saveBtn) return;

  // ── 변경 감지 ─────────────────────────────────────────────────

  function getRowValues(row) {
    return {
      cap_per_day:  parseInt(row.querySelector('[name="cap_per_day"]').value, 10) || 0,
      cap_per_week: parseInt(row.querySelector('[name="cap_per_week"]').value, 10) || 0,
      cap_priority: parseInt(row.querySelector('[name="cap_priority"]').value, 10) || 0,
      suppresses_segment_ids: getRowSuppressIds(row),
    };
  }

  function getRowSuppressIds(row) {
    const checks = row.querySelectorAll('.suppress-check:checked');
    return Array.from(checks).map(c => c.value);
  }

  function getRowOriginal(row) {
    return {
      cap_per_day:  parseInt(row.dataset.originalCapDay, 10) || 0,
      cap_per_week: parseInt(row.dataset.originalCapWeek, 10) || 0,
      cap_priority: parseInt(row.dataset.originalPriority, 10) || 0,
      suppresses_segment_ids: JSON.parse(row.dataset.originalSuppress || '[]'),
    };
  }

  function arraysEqual(a, b) {
    if (a.length !== b.length) return false;
    const sa = [...a].sort();
    const sb = [...b].sort();
    return sa.every((v, i) => v === sb[i]);
  }

  function isRowChanged(row) {
    const cur  = getRowValues(row);
    const orig = getRowOriginal(row);
    return cur.cap_per_day !== orig.cap_per_day
        || cur.cap_per_week !== orig.cap_per_week
        || cur.cap_priority !== orig.cap_priority
        || !arraysEqual(cur.suppresses_segment_ids, orig.suppresses_segment_ids);
  }

  function refreshChangeState() {
    let anyChanged = false;
    table.querySelectorAll('tbody tr[data-id]').forEach(row => {
      const changed = isRowChanged(row);
      row.classList.toggle('table-warning', changed);
      if (changed) anyChanged = true;
    });
    saveBtn.disabled = !anyChanged;
  }

  // suppress 체크박스 변경 시 버튼 라벨도 갱신
  function refreshSuppressBadges(row) {
    const btn = row.querySelector('.suppress-dropdown .dropdown-toggle');
    if (!btn) return;
    const checked = row.querySelectorAll('.suppress-check:checked');
    if (checked.length === 0) {
      btn.innerHTML = '<span class="text-muted">없음</span>';
    } else {
      btn.innerHTML = Array.from(checked).map(c => {
        const label = c.closest('label').textContent.trim();
        return '<span class="badge bg-info text-dark me-1">' + label + '</span>';
      }).join('');
    }
  }

  // 이벤트 위임
  table.addEventListener('input', (e) => {
    if (e.target.classList.contains('rule-input')) {
      refreshChangeState();
    }
  });
  table.addEventListener('change', (e) => {
    if (e.target.classList.contains('suppress-check')) {
      const row = e.target.closest('tr');
      refreshSuppressBadges(row);
      refreshChangeState();
    }
  });

  // ── 일괄 저장 ─────────────────────────────────────────────────

  saveBtn.addEventListener('click', async () => {
    const changed = [];
    table.querySelectorAll('tbody tr[data-id]').forEach(row => {
      if (!isRowChanged(row)) return;
      const vals = getRowValues(row);
      changed.push({
        id: row.dataset.id,
        cap_per_day: vals.cap_per_day,
        cap_per_week: vals.cap_per_week,
        cap_priority: vals.cap_priority,
        suppresses_segment_ids: vals.suppresses_segment_ids,
      });
    });

    if (changed.length === 0) return;

    saveBtn.disabled = true;
    saveBtn.textContent = '저장 중...';

    try {
      const res = await fetch(APP_URL + '/api/rules', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ segments: changed }),
      });
      const data = await res.json();

      if (!data.success) {
        alert('저장 실패: ' + (data.error || '알 수 없는 오류'));
        saveBtn.disabled = false;
        saveBtn.textContent = '일괄 저장';
        return;
      }

      // 성공 — original 값 갱신 + 하이라이트 제거
      table.querySelectorAll('tbody tr[data-id]').forEach(row => {
        const vals = getRowValues(row);
        row.dataset.originalCapDay   = vals.cap_per_day;
        row.dataset.originalCapWeek  = vals.cap_per_week;
        row.dataset.originalPriority = vals.cap_priority;
        row.dataset.originalSuppress = JSON.stringify(vals.suppresses_segment_ids);
        row.classList.remove('table-warning');
      });

      saveBtn.disabled = true;
      saveBtn.textContent = '일괄 저장';

      // Bootstrap toast
      const toastEl = document.getElementById('save-toast');
      if (toastEl) {
        const toast = new bootstrap.Toast(toastEl, { delay: 2500 });
        toast.show();
      }
    } catch (e) {
      alert('네트워크 오류: ' + e.message);
      saveBtn.disabled = false;
      saveBtn.textContent = '일괄 저장';
    }
  });
});
