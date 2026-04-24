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
              + `<button class="btn btn-sm btn-outline-info" onclick="testSchedule('${s.id}')">테스트</button>`
              + `<button class="btn btn-sm btn-outline-success" onclick="doSchedule('${s.id}')">예약</button>`
              + `<button class="btn btn-sm btn-outline-danger" onclick="deleteSchedule('${s.id}')">✕</button>`
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
