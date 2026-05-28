<?php
// pages/campaigns/detail.php — $id는 router에서 주입
require_once __DIR__ . '/../../src/Suppression.php';
require_once __DIR__ . '/../../src/SendCap.php';

$c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
if (!$c) { header('Location: ' . APP_URL . '/campaigns'); exit; }
$title   = '캠페인: ' . htmlspecialchars($c['name']);
$scripts = ['campaign-ui.js', 'campaign-actions.js', 'campaign-polling.js'];
include __DIR__ . '/../layout_header.php';

// 추출 예정 시각 계산 (send_time - 16h)
$extract_at = $c['send_time'] ? date('Y-m-d H:i', strtotime($c['send_time']) - 16 * 3600) : null;
$can_edit   = in_array($c['status'], ['awaiting_approval', 'failed', 'draft']);

// 발송 일시까지 남은 시간 (시간 단위)
$send_ts        = $c['send_time'] ? strtotime($c['send_time']) : 0;
$hours_to_send  = $send_ts ? (int)round(($send_ts - time()) / 3600) : null;
$is_overdue     = $hours_to_send !== null && $hours_to_send < 0;
$is_urgent      = $hours_to_send !== null && $hours_to_send >= 0 && $hours_to_send <= 16;

// G3 — VVIP suppression 영향 (양방향 정보)
// 마이그레이션(vvip_suppression.sql) 미적용 환경에서도 페이지가 깨지지 않도록 try/catch 로 감싼다.
// 컬럼/테이블 미존재 시 suppression 박스만 숨기고 나머지 캠페인 상세는 정상 렌더.
$send_date             = Suppression::extractSendDate((string)($c['send_time'] ?? ''));
$my_supp_targets       = [];
$suppressed_targets    = [];
$suppressors_on_day    = [];
$suppress_total_emails = 0;
$suppression_warning   = null;

try {
    $seg = DB::one('SELECT id, name, suppresses_segment_ids FROM segments WHERE id=?', [$c['segment_id']]);
    $my_supp_targets = Suppression::decode($seg['suppresses_segment_ids'] ?? null);

    // (a) 본 캠페인이 suppressor 인 경우 — 영향받는 같은 날 캠페인 조회
    if ($send_date !== '' && !empty($my_supp_targets)) {
        $seg_ph = implode(',', array_fill(0, count($my_supp_targets), '?'));
        $st_ph  = implode(',', array_fill(0, count(Suppression::ACTIVE_STATES), '?'));
        $params = array_merge($my_supp_targets, [$send_date], Suppression::ACTIVE_STATES);
        $suppressed_targets = DB::all(
            "SELECT id, name, segment_name, lead_count, status FROM campaigns
              WHERE segment_id IN ($seg_ph)
                AND DATE(send_time) = ?
                AND status IN ($st_ph) AND id != ?
              ORDER BY name",
            array_merge($params, [$c['id']])
        );
    }

    // (b) 본 캠페인이 피suppress 인 경우 — 같은 날 활성 suppressor 캠페인 조회
    if ($send_date !== '' && $c['segment_id']) {
        $rows = DB::all(
            'SELECT id FROM segments WHERE JSON_CONTAINS(suppresses_segment_ids, JSON_QUOTE(?))',
            [$c['segment_id']]
        );
        if (!empty($rows)) {
            $sup_seg_ids = array_column($rows, 'id');
            $seg_ph = implode(',', array_fill(0, count($sup_seg_ids), '?'));
            $st_ph  = implode(',', array_fill(0, count(Suppression::ACTIVE_STATES), '?'));
            $params = array_merge($sup_seg_ids, [$send_date], Suppression::ACTIVE_STATES);
            $suppressors_on_day = DB::all(
                "SELECT id, name, segment_name, lead_count, status FROM campaigns
                  WHERE segment_id IN ($seg_ph)
                    AND DATE(send_time) = ?
                    AND status IN ($st_ph)
                  ORDER BY name",
                $params
            );
        }
    }
    if ($send_date !== '' && $c['segment_id']) {
        $suppress_total_emails = count(Suppression::computeEmails((string)$c['segment_id'], $send_date));
    }
} catch (Throwable $e) {
    // vvip_suppression.sql 미적용(컬럼/테이블 부재) 또는 기타 도메인 외 오류는 페이지 차단을 막고 경고만 표시.
    error_log('[detail.php VVIP suppression] ' . $e->getMessage());
    $suppression_warning = $e->getMessage();
}

// 리드별 cap 영향 — lead_send_cap.sql 미적용 시에도 페이지 정상 렌더되도록 try/catch.
$cap_summary  = null;
$cap_warning  = null;
try {
    if ($c['segment_id'] && $send_date !== '') {
        $cap_summary = SendCap::summaryForCampaign((string)$c['id'], (string)$c['segment_id'], $send_date);
    }
} catch (Throwable $e) {
    error_log('[detail.php SendCap] ' . $e->getMessage());
    $cap_warning = $e->getMessage();
}
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
  <?php if ($suppression_warning !== null): ?>
  <div class="col-12">
    <div class="alert alert-warning small mb-0">
      ⚠️ <strong>VVIP Suppression 정보 로드 실패</strong>
      <span class="text-muted">— 캠페인 상세는 정상 표시되었으나, 우선순위 정보 박스는 일시적으로 숨겼습니다.</span>
      <details class="mt-1">
        <summary class="text-muted">기술 정보 (운영자 전용)</summary>
        <div class="mt-1">
          <code class="text-break"><?= htmlspecialchars($suppression_warning) ?></code>
          <div class="mt-1 text-muted">
            마이그레이션 미적용일 가능성이 높습니다. phpMyAdmin 에서 다음 파일을 실행하세요:<br>
            <code>sql/migrations/vvip_suppression.sql</code>
          </div>
        </div>
      </details>
    </div>
  </div>
  <?php endif; ?>
  <?php if (!empty($suppressed_targets) || !empty($suppressors_on_day)): ?>
  <div class="col-12">
    <div class="card border-info">
      <div class="card-header bg-info bg-opacity-10">
        <strong>🛡️ 발송 우선순위 (Suppression) 영향</strong>
        <small class="text-muted">— 같은 날(<?= htmlspecialchars($send_date) ?>) 발송 기준</small>
      </div>
      <div class="card-body small">
        <?php if (!empty($suppressed_targets)): ?>
          <div class="mb-2">
            <strong>이 캠페인이 영향 주는 캠페인</strong>
            <span class="badge bg-info"><?= count($suppressed_targets) ?>건</span>
            <span class="text-muted">— 본 캠페인 모수(<?= number_format((int)$c['lead_count']) ?>명)가 아래 캠페인 대상에서 자동 제외됩니다.</span>
          </div>
          <ul class="mb-3">
            <?php foreach ($suppressed_targets as $t): ?>
              <li>
                <a href="<?= APP_URL ?>/campaigns/<?= htmlspecialchars($t['id']) ?>"><?= htmlspecialchars($t['name']) ?></a>
                <span class="text-muted">[<?= htmlspecialchars($t['segment_name']) ?>]</span>
                — 대상 <?= number_format((int)$t['lead_count']) ?>명, status=<code><?= htmlspecialchars($t['status']) ?></code>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if (!empty($suppressors_on_day)): ?>
          <div class="mb-2">
            <strong>이 캠페인이 영향 받는 suppressor</strong>
            <span class="badge bg-warning"><?= count($suppressors_on_day) ?>건</span>
            <span class="text-muted">— 본 캠페인 추출 시 아래 캠페인 모수(<?= number_format($suppress_total_emails) ?>명)가 NOT IN 으로 제외됩니다.</span>
          </div>
          <ul class="mb-0">
            <?php foreach ($suppressors_on_day as $t): ?>
              <li>
                <a href="<?= APP_URL ?>/campaigns/<?= htmlspecialchars($t['id']) ?>"><?= htmlspecialchars($t['name']) ?></a>
                <span class="text-muted">[<?= htmlspecialchars($t['segment_name']) ?>]</span>
                — 모수 <?= number_format((int)$t['lead_count']) ?>명, status=<code><?= htmlspecialchars($t['status']) ?></code>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($cap_warning !== null): ?>
  <div class="col-12">
    <div class="alert alert-warning small mb-0">
      ⚠️ <strong>리드별 cap 정보 로드 실패</strong>
      <span class="text-muted">— 본 박스만 숨김 처리, 다른 화면은 정상 표시.</span>
      <details class="mt-1">
        <summary class="text-muted">기술 정보 (운영자 전용)</summary>
        <div class="mt-1">
          <code class="text-break"><?= htmlspecialchars($cap_warning) ?></code>
          <div class="mt-1 text-muted">
            마이그레이션 미적용일 가능성이 높습니다. phpMyAdmin 에서 다음 파일을 실행하세요:<br>
            <code>sql/migrations/lead_send_cap.sql</code>
          </div>
        </div>
      </details>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($cap_summary !== null && ($cap_summary['cap_per_day'] > 0 || $cap_summary['cap_per_week'] > 0)): ?>
  <div class="col-12">
    <div class="card border-secondary">
      <div class="card-header bg-light">
        <strong>🚦 발송 빈도 cap 영향</strong>
        <small class="text-muted">— 본 세그먼트 추출 시 적용</small>
      </div>
      <div class="card-body small">
        <div class="row g-3">
          <div class="col-md-4">
            <div class="text-muted">본 세그먼트 cap 정책</div>
            <div>
              일 <strong><?= $cap_summary['cap_per_day'] === 0 ? '무제한' : $cap_summary['cap_per_day'] . '통' ?></strong>
              · 주 <strong><?= $cap_summary['cap_per_week'] === 0 ? '무제한' : $cap_summary['cap_per_week'] . '통' ?></strong>
              · priority <strong><?= $cap_summary['cap_priority'] ?></strong>
            </div>
          </div>
          <div class="col-md-4">
            <div class="text-muted">추출 시점 cap 위반 예상</div>
            <div>
              <strong><?= number_format($cap_summary['blocked_estimate']) ?>명</strong>
              <span class="text-muted">제외 (priority ≥ <?= $cap_summary['cap_priority'] ?> 인 다른 캠페인 점유)</span>
            </div>
          </div>
          <div class="col-md-4">
            <div class="text-muted">본 캠페인 박제 상태</div>
            <div>
              hold <strong><?= number_format($cap_summary['hold']) ?>건</strong>
              · sent <strong><?= number_format($cap_summary['sent']) ?>건</strong>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

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

          <!-- Sprint 2 ORCH ⑮ — 직전 회차 비교 + 인박스 미리보기 -->
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <h6 class="text-muted text-uppercase small mb-2">직전 회차 비교</h6>
              <div id="previous-cohort" class="border rounded p-2 bg-light small text-muted">
                <div class="spinner-border spinner-border-sm" role="status"></div>
                불러오는 중...
              </div>
            </div>
            <div class="col-md-6">
              <h6 class="text-muted text-uppercase small mb-2">인박스 미리보기</h6>
              <div id="inbox-preview" class="inbox-preview-static border rounded p-2 bg-white"></div>
            </div>
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
            <div class="form-check border-top pt-2 mt-2">
              <input class="form-check-input approval-check" type="checkbox" id="chk-marketo-asset">
              <label class="form-check-label" for="chk-marketo-asset">
                Marketo UI에서 발송 Program의 Send Email 에셋이 <code><?= htmlspecialchars($c['asset_name'] ?? '') ?></code> 인지 확인했는가
              </label>
            </div>
            <div class="small text-muted mt-2">
              🔍 토큰 4종은 승인 시 Marketo API로 자동 검증됩니다 — 불일치 시 예약이 차단됩니다.
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
// ORCH Sprint 0 — 5분 취소 윈도 카운트다운.
//
// SEV1 RCA(2026-05-22) 후속 — 본 ISO 계산도 KST→UTC 명시 변환 필요. 과거 코드는
//   date('Y-m-d\TH:i:s', strtotime($raw_st)) . '+0000'
// 형태로 KST wall-clock 을 그대로 +0000 으로 라벨링 → 브라우저가 KST 19:00 으로 해석 →
// 카운트다운이 9h 짧게 표시. SEV1 RC#1 안티패턴 그대로 재발 (code-reviewer C-2).
// format_send_time_for_marketo() 로 통일.
$send_dt_iso = null;
if ($c['status'] === 'scheduled') {
    $raw_st = $c['send_time'] ?? '';
    try {
        if (strlen($raw_st) > 5) {
            $send_dt_iso = format_send_time_for_marketo($raw_st);
        } elseif (preg_match('/^\d{2}:\d{2}$/', $raw_st)) {
            $date_part   = date('Y-m-d', strtotime($c['scheduled_at']));
            $send_dt_iso = format_send_time_for_marketo($date_part . 'T' . $raw_st);
        } else {
            // scheduled_at 은 DB 저장 시각 (서버 기본 TZ). format_send_time_for_marketo 가
            // KST wall-clock 해석으로 통일된 변환을 보장하도록 동일 헬퍼 사용.
            $iso_input   = date('Y-m-d\TH:i:s', strtotime((string)$c['scheduled_at']));
            $send_dt_iso = format_send_time_for_marketo($iso_input);
        }
    } catch (Throwable $e) {
        $send_dt_iso = null; // 파싱 실패 시 카운트다운 자체 비활성
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
    <p class="mb-2">대상자 <strong id="bulk-total-label"><?= number_format((int)$c['lead_count']) ?>명</strong>을 Bulk Import API로 업로드하고 있습니다.</p>

    <!-- Sprint 3 ORCH — progress bar + 처리량 + ETA. /api/campaigns/{id}/bulk-progress 폴링 -->
    <div class="progress mb-2" style="height: 22px;" role="progressbar" aria-label="Bulk Import 진행률">
      <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning"
           id="bulk-progress-bar" style="width: 0%;">0%</div>
    </div>
    <div class="d-flex justify-content-between small text-muted mb-2" id="bulk-progress-stats">
      <span id="bulk-progress-counts">처리: 0 / <?= number_format((int)$c['lead_count']) ?></span>
      <span id="bulk-progress-rate">속도: - rows/s</span>
      <span id="bulk-progress-eta">남은 시간: 계산 중</span>
    </div>
    <p class="mb-0 text-muted small">
      batchId: <code><?= htmlspecialchars($c['bulk_job_id'] ?? '') ?></code> ·
      시작: <?= $c['bulk_started_at'] ? substr($c['bulk_started_at'], 0, 16) : '-' ?> ·
      <span id="bulk-progress-updated">갱신 중...</span>
    </p>
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

<?php if (in_array($c['status'], ['sent', 'scheduled'], true)): ?>
<!-- Sprint 2 ORCH ⑱ — 코호트 추세 (직전 5회차) -->
<div class="card mb-4">
  <div class="card-header"><strong>이 세그먼트의 코호트 추세</strong> <span class="text-muted small">(직전 5회차)</span></div>
  <div class="card-body">
    <div id="cohort-trend" class="text-muted small">
      <div class="spinner-border spinner-border-sm" role="status"></div>
      불러오는 중...
    </div>
  </div>
</div>
<?php endif; ?>

<?php
  $log_auto_expand = in_array($c['status'], ['scheduling', 'bulk_polling', 'bulk_finalizing'], true);
?>
<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center log-collapse-header"
       data-bs-toggle="collapse" data-bs-target="#log-collapse"
       role="button" aria-expanded="<?= $log_auto_expand ? 'true' : 'false' ?>">
    <strong>실행 로그</strong>
    <span class="badge bg-secondary" id="log-count">0</span>
  </div>
  <div class="collapse<?= $log_auto_expand ? ' show' : '' ?>" id="log-collapse">
    <div class="card-body p-0">
      <table class="table table-sm mb-0" id="log-table">
        <thead><tr><th>단계</th><th>상태</th><th>메시지</th><th>시각</th></tr></thead>
        <tbody id="log-body"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
const APP_URL       = '<?= APP_URL ?>';
const CAMPAIGN_ID   = '<?= $c['id'] ?>';
const CAMPAIGN_SEGMENT_ID = '<?= $c['segment_id'] ?>';
const POLL_STATUS   = '<?= $c['poll_status'] ?? 'idle' ?>';
const CAMPAIGN_STATUS = '<?= $c['status'] ?>';
const APPROVAL_TOKENS = {
  emoji:      <?= json_encode($c['emoji'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
  title:      <?= json_encode($c['email_title'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
  preheader:  <?= json_encode($c['email_preheader'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
  reward_url: <?= json_encode($c['reward_url'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
};
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
