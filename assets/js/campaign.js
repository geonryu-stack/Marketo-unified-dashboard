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
