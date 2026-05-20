<?php
// pages/campaigns/detail.php — $id는 router에서 주입
$c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
if (!$c) { header('Location: ' . APP_URL . '/campaigns'); exit; }
$title   = '캠페인: ' . htmlspecialchars($c['name']);
$scripts = ['campaign.js'];
include __DIR__ . '/../layout_header.php';

// 추출 예정 시각 계산 (send_time - 16h)
$extract_at = $c['send_time'] ? date('Y-m-d H:i', strtotime($c['send_time']) - 16 * 3600) : null;
$can_edit   = in_array($c['status'], ['awaiting_approval', 'failed', 'draft']);

// 발송 일시까지 남은 시간 (시간 단위)
$send_ts        = $c['send_time'] ? strtotime($c['send_time']) : 0;
$hours_to_send  = $send_ts ? (int)round(($send_ts - time()) / 3600) : null;
$is_overdue     = $hours_to_send !== null && $hours_to_send < 0;
$is_urgent      = $hours_to_send !== null && $hours_to_send >= 0 && $hours_to_send <= 16;
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
        <dt class="col-sm-5">이메일 발송 일시</dt>
        <dd class="col-sm-7"><?= $c['send_time'] ? substr($c['send_time'], 0, 16) : '-' ?></dd>
        <dt class="col-sm-5">추출 예정 시각</dt>
        <dd class="col-sm-7 text-muted"><?= $extract_at ?? '-' ?> <small>(발송 16h 전 자동 예약)</small></dd>
        <dt class="col-sm-5">대상자</dt>
        <dd class="col-sm-7"><?= $c['lead_count'] > 0 ? number_format((int)$c['lead_count']) . '명' : '-' ?></dd>
      </dl>
    </div></div>
  </div>
  <div class="col-md-6">
    <div class="card"><div class="card-body">
      <h6 class="card-title text-muted mb-2">이메일 컨텐츠 토큰</h6>
      <dl class="row mb-0 small">
        <dt class="col-sm-5"><code>my.Emoji</code></dt>
        <dd class="col-sm-7"><?= htmlspecialchars($c['emoji'] ?? '') ?: '<span class="text-muted">-</span>' ?></dd>
        <dt class="col-sm-5"><code>my.Title</code></dt>
        <dd class="col-sm-7"><?= htmlspecialchars($c['email_title'] ?? '') ?: '<span class="text-muted">-</span>' ?></dd>
        <dt class="col-sm-5"><code>my.Preheader</code></dt>
        <dd class="col-sm-7"><?= htmlspecialchars($c['email_preheader'] ?? '') ?: '<span class="text-muted">-</span>' ?></dd>
        <dt class="col-sm-5"><code>my.RewardUrl</code></dt>
        <dd class="col-sm-7 text-break"><?= htmlspecialchars($c['reward_url'] ?? '') ?: '<span class="text-muted">-</span>' ?></dd>
      </dl>
    </div></div>
  </div>
  <?php if ($c['error_message']): ?>
  <div class="col-12">
    <div class="alert alert-danger"><pre class="mb-0" style="white-space:pre-wrap;font-family:inherit;font-size:inherit;color:inherit;"><?= htmlspecialchars($c['error_message']) ?></pre></div>
  </div>
  <?php endif; ?>

  <?php if ($c['status'] === 'needs_manual_review'): ?>
  <div class="col-12">
    <div class="card border-danger">
      <div class="card-header bg-danger text-white">
        <strong>⚠️ 수동 검토 필요 — Marketo Email Program 상태 확인 후 결정하세요</strong>
      </div>
      <div class="card-body">
        <p class="mb-2"><strong>EP 변경 도중 오류가 발생하여 Marketo 측 상태가 불확실합니다.</strong></p>
        <p class="mb-2 small text-muted">
          이 상태에서는 같은 세그먼트의 다른 캠페인이 자동으로 차단되어 EP 덮어쓰기를 방지합니다.
          아래 두 옵션 중 하나를 선택하기 전에, <strong>Marketo UI</strong>에서 해당 Email Program이 정상 예약됐는지 확인하세요.
        </p>
        <div class="mb-2">
          <label class="form-label small mb-1">결정 메모 (선택)</label>
          <input type="text" class="form-control form-control-sm" id="review-note"
                 placeholder="예: Marketo UI 확인 결과 EP가 정상 scheduled 상태임">
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button type="button" class="btn btn-success btn-sm" onclick="campaign.resolveReview('scheduled')">
            ✓ Marketo에서 정상 예약됨 확인 → Scheduled로 표시
          </button>
          <button type="button" class="btn btn-outline-danger btn-sm" onclick="campaign.resolveReview('failed')">
            ✗ Marketo 측 미처리 확인 → Failed로 표시
          </button>
        </div>
        <p class="mb-0 mt-2 small text-muted">
          • <strong>Scheduled</strong>: 같은 세그먼트의 다른 캠페인 차단 유지 (EP 보호) — Marketo가 실제 예약된 경우만 선택<br>
          • <strong>Failed</strong>: sibling 차단 해제 — Marketo에서 EP를 정리하거나 unapprove 한 경우 선택
        </p>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($c['status'] === 'awaiting_approval'): ?>
    <div class="col-12">
      <div class="card border-warning" id="approval-card">
        <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
          <strong>⚠️ 결재 대기 — 테스트 메일 확인 후 발송 승인을 결정하세요</strong>
          <?php if ($hours_to_send !== null): ?>
            <?php if ($is_overdue): ?>
              <span class="badge bg-danger">발송 일시 경과 (<?= abs($hours_to_send) ?>시간 전)</span>
            <?php else: ?>
              <span class="badge bg-<?= $is_urgent ? 'danger' : 'light text-dark' ?>">발송까지 <?= $hours_to_send ?>시간</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if ($is_overdue): ?>
          <div class="alert alert-danger py-2 mb-3">
            ⚠️ <strong>발송 일시가 이미 지났습니다.</strong>
            "편집하고 다시 테스트" 버튼으로 send_time을 재설정하거나, 캠페인을 삭제하세요. 이 상태로 승인하면 Marketo가 거부할 수 있습니다.
          </div>
          <?php elseif ($is_urgent): ?>
          <div class="alert alert-danger py-2 mb-3">
            ⚠️ <strong>발송 일시가 16시간 이내입니다.</strong>
            대상자 추출/Bulk 업로드 소요 시간(~5분)을 감안해 즉시 검토하세요.
          </div>
          <?php endif; ?>

          <p class="text-muted small mb-3">
            좌측의 캠페인 기본 정보와 토큰 미리보기를 확인한 후, 아래 체크리스트를 모두 점검해야 승인 버튼이 활성화됩니다.
          </p>

          <?php
            $ss_path = $c['test_screenshot_path'] ?? null;
            $ss_url  = $ss_path ? (rtrim(APP_URL, '/') . '/' . ltrim((string)$ss_path, '/')) : null;
          ?>
          <div class="mb-3 border rounded p-2 bg-light" id="screenshot-slot">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <strong class="small">📸 테스트 메일 스크린샷 첨부 (선택)</strong>
              <small class="text-muted">jpg / png / webp · 5MB 이하 · 결재 흔적 보존용</small>
            </div>
            <?php if ($ss_url): ?>
              <div class="d-flex align-items-start gap-3">
                <a href="<?= htmlspecialchars($ss_url) ?>" target="_blank" rel="noopener">
                  <img src="<?= htmlspecialchars($ss_url) ?>" alt="테스트 메일 스크린샷"
                       style="max-width:200px;max-height:140px;border:1px solid #ddd;border-radius:4px;">
                </a>
                <div class="flex-grow-1">
                  <div class="small text-muted text-break mb-2"><code><?= htmlspecialchars($ss_path) ?></code></div>
                  <input type="file" class="form-control form-control-sm mb-2" id="screenshot-file"
                         accept="image/jpeg,image/png,image/webp">
                  <button type="button" class="btn btn-outline-secondary btn-sm"
                          onclick="campaign.uploadScreenshot()">재첨부</button>
                </div>
              </div>
            <?php else: ?>
              <div class="d-flex align-items-end gap-2">
                <input type="file" class="form-control form-control-sm" id="screenshot-file"
                       accept="image/jpeg,image/png,image/webp">
                <button type="button" class="btn btn-outline-primary btn-sm"
                        onclick="campaign.uploadScreenshot()">첨부</button>
              </div>
              <small class="text-muted d-block mt-1">
                테스트 메일을 받은 메일 클라이언트에서 캡처한 이미지를 올리면, 추후 감사·재발견 시 어떤 화면을 승인했는지 확인할 수 있습니다.
              </small>
            <?php endif; ?>
          </div>

          <div class="mb-3">
            <strong class="small">📋 발송 전 체크리스트</strong>
            <div class="form-check mt-2">
              <input class="form-check-input approval-check" type="checkbox" id="chk-tokens">
              <label class="form-check-label" for="chk-tokens">
                토큰 4종(Emoji / Title / Preheader / RewardUrl) 값이 정확한가
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input approval-check" type="checkbox" id="chk-sendtime">
              <label class="form-check-label" for="chk-sendtime">
                발송 일시가 정확한가 (<?= $c['send_time'] ? substr($c['send_time'], 0, 16) : '미설정' ?>)
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input approval-check" type="checkbox" id="chk-leadcount">
              <label class="form-check-label" for="chk-leadcount">
                대상자 세그먼트가 예상 범위인가
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input approval-check" type="checkbox" id="chk-testmail">
              <label class="form-check-label" for="chk-testmail">
                테스트 메일을 받아 렌더링이 정상임을 확인했는가
              </label>
            </div>
          </div>

          <div class="d-flex gap-2 flex-wrap mb-3 border-bottom pb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="campaign.resendTestEmail()">
              📧 테스트 메일 재발송
            </button>
            <a href="<?= APP_URL ?>/campaigns/<?= $c['id'] ?>/edit" class="btn btn-outline-secondary btn-sm">
              ✏️ 편집하고 다시 테스트
            </a>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-success" id="btn-approve" disabled
                    onclick="campaign.approve()">
              ✅ 발송 승인
            </button>
            <button type="button" class="btn btn-outline-danger" onclick="campaign.reject()">
              🔄 거절 (재검토)
            </button>
            <small class="text-muted ms-auto align-self-center">
              승인 시 즉시 Marketo 리스트 업로드 + Email Program 예약을 실행합니다 (1~5분 소요)
            </small>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php
// ORCH Sprint 0 — 5분 취소 윈도 카운트다운
// 발송 예정 시각 = scheduled_at 날짜 + send_time(HH:MM) UTC. api/campaigns.php의 schedule 로직과 일치.
$send_dt_iso = null;
if ($c['status'] === 'scheduled') {
    $raw_st = $c['send_time'] ?? '';
    if (strlen($raw_st) > 5) {
        // full datetime (YYYY-MM-DDTHH:MM)
        $send_dt_iso = date('Y-m-d\TH:i:s', strtotime($raw_st)) . '+0000';
    } elseif (preg_match('/^\d{2}:\d{2}$/', $raw_st)) {
        $date_part   = date('Y-m-d', strtotime($c['scheduled_at']));
        $send_dt_iso = $date_part . 'T' . $raw_st . ':00+0000';
    } else {
        $send_dt_iso = date('Y-m-d\TH:i:s', strtotime($c['scheduled_at'])) . '+0000';
    }
}
?>
<?php if ($c['status'] === 'scheduled'): ?>
<div id="cancel-countdown" class="cancel-countdown mb-3" data-send-time="<?= htmlspecialchars($send_dt_iso) ?>">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <div class="cancel-countdown-label">발송까지 남은 시간</div>
      <div class="cancel-countdown-clock" id="cancel-countdown-clock">--:--</div>
    </div>
    <button class="btn btn-outline-danger btn-lg" id="cancel-countdown-btn" onclick="campaign.cancel()">예약 취소</button>
  </div>
</div>
<?php endif; ?>

<div class="d-flex gap-2 mb-4 flex-wrap">
  <?php if ($can_edit && $c['status'] !== 'awaiting_approval'): ?>
    <a href="<?= APP_URL ?>/campaigns/<?= $c['id'] ?>/edit" class="btn btn-primary">편집 및 테스트 재발송</a>
  <?php endif; ?>
  <button class="btn btn-outline-primary" onclick="campaign.duplicate()">다음 회차 복제</button>
  <button class="btn btn-outline-danger ms-auto" onclick="campaign.deleteCampaign()">삭제</button>
</div>

<?php if ($c['status'] === 'bulk_polling'): ?>
<div class="card mt-3 mb-3" id="bulk-progress-card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>대용량 업로드 진행 중</strong>
    <span class="badge bg-warning" id="bulk-status-badge"><?= htmlspecialchars($c['bulk_status'] ?? 'Importing') ?></span>
  </div>
  <div class="card-body" id="bulk-progress-body">
    <p class="mb-1">대상자 <strong><?= number_format((int)$c['lead_count']) ?>명</strong>을 Bulk Import API로 업로드하고 있습니다.</p>
    <p class="mb-1 text-muted small">batchId: <code><?= htmlspecialchars($c['bulk_job_id'] ?? '') ?></code></p>
    <p class="mb-0 text-muted small">시작: <?= $c['bulk_started_at'] ? substr($c['bulk_started_at'], 0, 16) : '-' ?> · 30초마다 자동 갱신</p>
  </div>
</div>
<?php endif; ?>

<?php $poll_status = $c['poll_status'] ?? 'idle'; ?>
<?php if ($poll_status !== 'idle'): ?>
<div class="card mt-3 mb-3" id="delivery-result-card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>발송 결과</strong>
    <span class="badge bg-<?= $poll_status === 'done' ? 'success' : ($poll_status === 'timeout' ? 'warning' : 'info') ?>">
      <?= ['polling' => '수집 중', 'done' => '완료', 'timeout' => '8h 초과'][$poll_status] ?? $poll_status ?>
    </span>
  </div>
  <div class="card-body" id="delivery-stats">
    <span class="text-muted small">로딩 중...</span>
  </div>
</div>
<?php endif; ?>

<h5>실행 로그</h5>
<table class="table table-sm bg-white" id="log-table">
  <thead><tr><th>단계</th><th>상태</th><th>메시지</th><th>시각</th></tr></thead>
  <tbody id="log-body"></tbody>
</table>

<script>
const APP_URL       = '<?= APP_URL ?>';
const CAMPAIGN_ID   = '<?= $c['id'] ?>';
const POLL_STATUS   = '<?= $c['poll_status'] ?? 'idle' ?>';
const CAMPAIGN_STATUS = '<?= $c['status'] ?>';
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
