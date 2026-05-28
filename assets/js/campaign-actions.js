// assets/js/campaign-actions.js
// 캠페인 액션: 승인, 거절, 취소, 복제, 삭제, 수동 검토 해제
// Used by: campaigns/detail.php, isolation_queue.php

const campaign = {
  // ── 결재 카드 체크리스트 게이팅 ─────────────────────────────
  _bindApprovalChecklist() {
    const boxes = document.querySelectorAll('.approval-check');
    const btn   = document.getElementById('btn-approve');
    if (!boxes.length || !btn) return;
    const sync = () => {
      const allChecked = Array.from(boxes).every(b => b.checked);
      btn.disabled = !allChecked;
    };
    boxes.forEach(b => b.addEventListener('change', sync));
    sync();
  },

  // ── 결재 승인 ───────────────────────────────────────────────
  async approve() {
    const boxes = document.querySelectorAll('.approval-check');
    const allChecked = Array.from(boxes).every(b => b.checked);
    if (!allChecked) {
      alert('체크리스트 항목을 모두 확인해야 승인할 수 있습니다.');
      return;
    }

    // Step 1 — type-to-confirm 모달
    const confirmed = await campaign._confirmApprove();
    if (!confirmed) return;

    // Step 2 — 진행 모달 열기 + logs polling 시작
    const progress = campaign._showProgressModal();
    const pollTimer = setInterval(loadLogs, 1500);

    // 서버측 강제 게이트 — 각 체크 항목의 통과 신호를 명시 전달.
    // 서버는 5개 키가 모두 true 일 때만 통과. DOM 우회·직접 fetch 호출에서도 동작.
    // (2026-05-28) 토큰은 자동 검증. 에셋은 API 한계로 수동 확인 유지 (Smart Campaign
    // Flow 의 Send Email 스텝은 REST API 로 조회 불가).
    const _val = (id) => !!document.getElementById(id)?.checked;
    const confirmations = {
      tokens:        _val('chk-tokens'),
      sendtime:      _val('chk-sendtime'),
      leadcount:     _val('chk-leadcount'),
      testmail:      _val('chk-testmail'),
      marketo_asset: _val('chk-marketo-asset'),
    };

    try {
      const approveBody = { confirmations };
      const doApprove = async (body) => {
        const r = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/approve`, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify(body),
        });
        return r.json();
      };

      const data = await doApprove(approveBody);
      clearInterval(pollTimer);

      // 자동 검증 불일치 → 스케줄링 차단됨. 운영자에게 강제 진행 선택지 제공.
      if (data.requires_force_verify) {
        const vw = data.verification?.warnings || [];
        progress.setState('error',
          '⚠️ 에셋·토큰 자동 검증 불일치로 예약이 차단되었습니다:\n• '
          + vw.join('\n• ')
          + '\n\nMarketo UI에서 직접 확인 후 강제 진행하거나, 캠페인을 수정하세요.',
          [
            {
              label: '확인 완료, 강제 진행',
              cls: 'btn-danger',
              onclick: async () => {
                progress.setState('success', '⏳ 강제 진행 중...');
                const pollTimer2 = setInterval(loadLogs, 1500);
                try {
                  const data2 = await doApprove({ ...approveBody, force_verify: true });
                  clearInterval(pollTimer2);
                  if (data2.success) {
                    progress.setState('success', '✅ 강제 진행으로 Marketo 예약이 완료되었습니다.');
                    setTimeout(() => location.reload(), 1200);
                  } else {
                    progress.setState('error', `예약 실패: ${data2.error || '알 수 없는 오류'}`, [
                      { label: '페이지 새로고침', cls: 'btn-primary', onclick: () => location.reload() },
                    ]);
                  }
                } catch (e2) {
                  clearInterval(pollTimer2);
                  progress.setState('error', `네트워크 오류: ${e2.message}`, [
                    { label: '페이지 새로고침', cls: 'btn-primary', onclick: () => location.reload() },
                  ]);
                }
              },
            },
            { label: '캠페인 수정', cls: 'btn-outline-secondary',
              onclick: () => { location.href = `${APP_URL}/campaigns/${CAMPAIGN_ID}/edit`; } },
            { label: '닫기', cls: 'btn-outline-secondary', onclick: () => { progress.close(); location.reload(); } },
          ]
        );
      } else if (data.success) {
        const vw = data.data?.verification?.warnings;
        if (vw && vw.length > 0) {
          progress.setState('success', '✅ Marketo 예약 완료.\n⚠️ 자동 검증 참고:\n• ' + vw.join('\n• '));
          setTimeout(() => location.reload(), 3000);
        } else {
          progress.setState('success', '✅ Marketo 예약이 완료되었습니다. 페이지를 새로고침합니다...');
          setTimeout(() => location.reload(), 1200);
        }
      } else if (data.conflict_id) {
        progress.setState('error', `세그먼트 충돌: ${data.error}`, [
          { label: '충돌 캠페인 열기', cls: 'btn-primary', onclick: () => { location.href = `${APP_URL}/campaigns/${data.conflict_id}`; } },
          { label: '닫기',             cls: 'btn-outline-secondary', onclick: () => progress.close() },
        ]);
      } else {
        progress.setState('error', `예약 실패: ${data.error || '알 수 없는 오류'}`, [
          { label: '페이지 새로고침', cls: 'btn-primary', onclick: () => location.reload() },
          { label: '닫기',            cls: 'btn-outline-secondary', onclick: () => progress.close() },
        ]);
      }
    } catch (e) {
      clearInterval(pollTimer);
      progress.setState('error', `네트워크 오류: ${e.message}`, [
        { label: '페이지 새로고침', cls: 'btn-primary', onclick: () => location.reload() },
      ]);
    }
  },

  // ── 결재 거절 ───────────────────────────────────────────────
  async reject() {
    const memo = prompt('거절 사유 메모 (선택, 비워두어도 됩니다):\n→ 캠페인을 draft로 되돌립니다.', '');
    if (memo === null) return; // 취소
    try {
      const res = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/reject`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ reject_memo: memo }),
      });
      const data = await res.json();
      if (data.success) location.reload();
      else alert('거절 실패: ' + (data.error || '알 수 없는 오류'));
    } catch (e) {
      alert('네트워크 오류: ' + e.message);
    }
  },

  // ── 테스트 메일 재발송 ──────────────────────────────────────
  async resendTestEmail() {
    if (!confirm('테스트 메일을 다시 발송하시겠습니까?')) return;
    try {
      const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/resend-test-email`, { method: 'POST' });
      const data = await res.json();
      if (data.success) {
        alert('테스트 메일을 재발송했습니다. 메일함을 확인하세요.');
        location.reload();
      } else {
        alert('재발송 실패: ' + (data.error || '알 수 없는 오류'));
      }
    } catch (e) {
      alert('네트워크 오류: ' + e.message);
    }
  },

  // ── 테스트 메일 스크린샷 첨부 (Sprint 1 ASSET) ───────────────
  async uploadScreenshot() {
    const input = document.getElementById('screenshot-file');
    if (!input || !input.files || !input.files[0]) {
      alert('첨부할 이미지 파일을 선택하세요.');
      return;
    }
    const file = input.files[0];
    // 클라이언트 측 1차 가드: 5MB / 화이트리스트 MIME (서버에서 재검증)
    const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];
    if (!ALLOWED.includes(file.type)) {
      alert('jpg, png, webp 형식만 업로드 가능합니다.');
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      alert('파일 크기는 5MB 이하여야 합니다.');
      return;
    }

    const fd = new FormData();
    fd.append('file', file);

    const btn = event && event.target ? event.target : null;
    if (btn) { btn.disabled = true; btn.textContent = '업로드 중...'; }

    try {
      const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/screenshot`, {
        method: 'POST',
        body:   fd,
      });
      const data = await res.json();
      if (data.success) {
        // 즉시 페이지 리로드: 첨부 슬롯이 썸네일 모드로 전환됨
        location.reload();
      } else {
        alert('스크린샷 첨부 실패: ' + (data.error || '알 수 없는 오류'));
        if (btn) { btn.disabled = false; btn.textContent = '첨부'; }
      }
    } catch (e) {
      alert('네트워크 오류: ' + e.message);
      if (btn) { btn.disabled = false; btn.textContent = '첨부'; }
    }
  },

  // ── 기존 액션 ───────────────────────────────────────────────
  async _action(action, method = 'POST') {
    const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/${action}`, { method });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.error || '오류가 발생했습니다.');
  },

  // SC cancel 처리 — 2단계 흐름:
  //  1) acknowledge_sent 없이 POST → 서버가 이미 sent 박제된 행 존재 시 409 + requires_acknowledgement=true
  //  2) 운영자에게 "이미 N명이 받았음" confirm 표시 후 acknowledge_sent=true 로 재요청
  // 서버 단독 게이트(api/campaigns.php H-3)는 *bypass 불가* 인데 UI 가 처리 못 하면 운영자가 영원히
  // cancel 못 함 → 본 UI 코드 누락 시 게이트가 사실상 unreachable (Codex stop-time review 지적).
  async cancel() {
    if (!confirm('Marketo 예약을 취소하시겠습니까?\n취소 후 캠페인은 결재 대기 상태로 돌아갑니다.\n\n' +
                 '※ Smart Campaign 모드의 경우, Marketo 발송 자체는 +2년 후로 reschedule 되며 ' +
                 '운영자가 Marketo UI 에서 schedule 을 직접 제거하는 것을 권장합니다.')) return;

    const url = `${APP_URL}/api/campaigns/${CAMPAIGN_ID}/cancel`;
    // 1차 시도 — acknowledge_sent 없이
    let res  = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({}),
    });
    let data = await res.json();

    // 409 + requires_acknowledgement → 운영자에게 이미 sent 된 인원 명시 + 재확인
    if (res.status === 409 && data?.requires_acknowledgement) {
      const sent_n = data.already_sent_count ?? 0;
      const proceed = confirm(
        `⚠ 이미 ${sent_n.toLocaleString()}명이 본 캠페인 이메일을 받았습니다.\n\n` +
        `cancel 은 *남은 발송* 만 중지하며, 이미 발송된 이메일은 회수할 수 없습니다.\n` +
        `계속 진행하시겠습니까?`
      );
      if (!proceed) return;

      res  = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ acknowledge_sent: true }),
      });
      data = await res.json();
    }

    if (data?.success) {
      // SC 모드 reschedule 안내 — alert 후 reload
      if (data.data?.manual_action_required) {
        alert('✅ 예약 취소 완료.\n\n' + data.data.manual_action_required);
      }
      location.reload();
    } else {
      alert(data?.error || '취소 처리 중 오류가 발생했습니다.');
    }
  },

  async duplicate() {
    if (!confirm('동일 설정으로 새 캠페인을 만드시겠습니까?\n발송 일시는 내일 같은 시각으로 설정됩니다.')) return;
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = '복제 중...';
    const res  = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/duplicate`, { method: 'POST' });
    const data = await res.json();
    if (data.success) location.href = `${APP_URL}/campaigns/${data.data.id}`;
    else { alert('복사 실패: ' + data.error); btn.disabled = false; btn.textContent = '다음 회차 복제'; }
  },

  deleteCampaign() {
    if (!confirm('삭제하시겠습니까? 복구할 수 없습니다.')) return;
    fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}`, { method: 'DELETE' })
      .then(() => { location.href = `${APP_URL}/campaigns`; });
  },

  async resolveReview(as) {
    const messages = {
      scheduled: '⚠️ Marketo UI에서 해당 Email Program이 정상 예약(scheduled)된 것을 확인했습니까?\n\n' +
                 '"확인"을 누르면 캠페인 상태를 \'예약 완료\'로 표시하고, 같은 세그먼트의 다른 캠페인 차단을 유지합니다.\n' +
                 '잘못 확인하면 발송이 누락될 수 있습니다.',
      failed:    '⚠️ Marketo UI에서 해당 Email Program이 미처리(draft/unapproved) 또는 정리됨을 확인했습니까?\n\n' +
                 '"확인"을 누르면 캠페인 상태를 \'실패\'로 표시하고, sibling 차단을 해제합니다.\n' +
                 '실제로 Marketo에 예약돼 있다면 다른 캠페인이 그 예약을 덮어쓸 수 있습니다.',
    };
    if (!confirm(messages[as])) return;

    const note = (document.getElementById('review-note')?.value || '').trim();
    try {
      const res = await fetch(`${APP_URL}/api/campaigns/${CAMPAIGN_ID}/resolve-review`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ as, operator_note: note }),
      });
      const data = await res.json();
      if (data.success) {
        location.reload();
      } else {
        alert('해제 실패: ' + (data.error || '알 수 없는 오류'));
      }
    } catch (e) {
      alert('네트워크 오류: ' + e.message);
    }
  },

  // ── 모달 헬퍼 ───────────────────────────────────────────────
  _confirmApprove() {
    // 한글 "발송" 두 글자 입력해야 활성화 (단순 confirm 키 연타 방지)
    return new Promise((resolve) => {
      const html = `
        <div class="modal fade" id="approve-confirm-modal" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">⚠️ 발송 승인 — 되돌릴 수 없습니다</h5>
              </div>
              <div class="modal-body">
                <p>승인 즉시 다음 작업이 동기로 실행됩니다 (1~5분):</p>
                <ul class="small text-muted mb-3">
                  <li>사내 DB에서 대상자 추출</li>
                  <li>Marketo Static List 업로드 (REST 또는 Bulk)</li>
                  <li>토큰 주입 + Email Program 예약 (RTZ)</li>
                </ul>
                <hr>
                <label class="form-label">아래 입력란에 <strong>발송</strong> 을 그대로 입력하세요:</label>
                <input type="text" class="form-control" id="confirm-input" autocomplete="off" placeholder="발송">
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-success" id="confirm-go" disabled>확인하고 승인</button>
              </div>
            </div>
          </div>
        </div>`;
      document.body.insertAdjacentHTML('beforeend', html);
      const modalEl = document.getElementById('approve-confirm-modal');
      const input   = document.getElementById('confirm-input');
      const goBtn   = document.getElementById('confirm-go');
      const modal   = new bootstrap.Modal(modalEl);

      input.addEventListener('input', () => {
        goBtn.disabled = input.value.trim() !== '발송';
      });
      goBtn.addEventListener('click', () => { modal.hide(); resolve(true); });
      modalEl.addEventListener('hidden.bs.modal', () => {
        modalEl.remove();
        if (!goBtn.dataset.fired) resolve(false);
      });
      goBtn.addEventListener('click', () => { goBtn.dataset.fired = '1'; });
      modal.show();
      setTimeout(() => input.focus(), 200);
    });
  },

  _showProgressModal() {
    const html = `
      <div class="modal fade" id="approve-progress-modal" tabindex="-1"
           data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="progress-title">⏳ Marketo 예약 진행 중 (1~5분 소요)</h5>
            </div>
            <div class="modal-body">
              <div class="alert alert-info py-2 small mb-3" id="progress-alert">
                페이지를 닫지 마세요. 단계별 진행 상황은 아래 로그 테이블이 실시간으로 갱신합니다.
              </div>
              <div class="d-flex align-items-center mb-3" id="progress-spinner">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                <span>대상자 추출 → Marketo 업로드 → Email Program 예약 진행 중...</span>
              </div>
              <div id="progress-actions" class="d-flex gap-2"></div>
            </div>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
    const modalEl = document.getElementById('approve-progress-modal');
    const modal   = new bootstrap.Modal(modalEl);
    modal.show();
    modalEl.addEventListener('hidden.bs.modal', () => modalEl.remove());

    return {
      setState(kind, message, actions = []) {
        const alert  = document.getElementById('progress-alert');
        const spin   = document.getElementById('progress-spinner');
        const title  = document.getElementById('progress-title');
        const acts   = document.getElementById('progress-actions');
        spin.style.display = 'none';
        if (kind === 'success') {
          title.textContent = '✅ 예약 완료';
          alert.className   = 'alert alert-success py-2 mb-3';
          alert.textContent = message;
        } else if (kind === 'error') {
          title.textContent = '❌ 예약 실패';
          alert.className   = 'alert alert-danger py-2 mb-3';
          alert.textContent = message;
          acts.innerHTML = '';
          actions.forEach(a => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn ' + a.cls;
            btn.textContent = a.label;
            btn.addEventListener('click', a.onclick);
            acts.appendChild(btn);
          });
        }
      },
      close() { modal.hide(); },
    };
  },
};

document.addEventListener('DOMContentLoaded', () => {
  campaign._bindApprovalChecklist();
});
