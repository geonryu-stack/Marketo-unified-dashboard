<?php
// pages/segments/_cap_section.php
// 리드(이메일 주소)별 일/주 단위 발송 cap UI 컴포넌트. new.php / edit.php 가 공통 include.
//
// Host file 이 미리 정의해야 하는 변수:
//   $current_cap_per_day   int|null   기존 cap_per_day. 신규는 null → 기본값 1
//   $current_cap_per_week  int|null   기존 cap_per_week. 신규는 null → 기본값 7
//   $current_cap_priority  int|null   기존 cap_priority. 신규는 null → 기본값 100

$_cap_day  = $current_cap_per_day  ?? 1;
$_cap_week = $current_cap_per_week ?? 7;
$_cap_pri  = $current_cap_priority ?? 100;
?>
<h5 class="mt-3">발송 빈도 cap <small class="text-muted fs-6 fw-normal">— 수신자 한 명이 같은 윈도우에 받는 발송 수 제한</small></h5>
<div class="card mb-4">
  <div class="card-body">
    <p class="small text-muted mb-3">
      이 세그먼트의 캠페인이 발송될 때, 같은 수신자가 다른 캠페인에서도 받는 빈도를 제한합니다.
      이메일 한 주소 기준으로 일·주 단위 cap 을 초과하면 본 세그먼트의 추출에서 자동 제외됩니다.
    </p>
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label small">일 cap <span class="text-muted">(0 = 무제한)</span></label>
        <div class="input-group input-group-sm">
          <input type="number" min="0" max="9999" step="1" class="form-control cap-input"
                 name="cap_per_day" value="<?= (int)$_cap_day ?>">
          <span class="input-group-text">통/일</span>
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label small">주 cap <span class="text-muted">(0 = 무제한)</span></label>
        <div class="input-group input-group-sm">
          <input type="number" min="0" max="9999" step="1" class="form-control cap-input"
                 name="cap_per_week" value="<?= (int)$_cap_week ?>">
          <span class="input-group-text">통/7일</span>
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label small">우선순위 <span class="text-muted">(큰 수가 우선)</span></label>
        <input type="number" min="0" max="9999" step="1" class="form-control form-control-sm cap-input"
               name="cap_priority" value="<?= (int)$_cap_pri ?>">
        <div class="form-text small">VVIP=200, 일반=100, 마케팅성=50 권장</div>
      </div>
      <div class="col-md-3">
        <div class="small text-muted">
          ℹ️ priority 가 본 세그먼트보다 같거나 큰 다른 캠페인이 이미 점유한 이메일은
          본 추출에서 자동 제외됩니다.
        </div>
      </div>
    </div>
  </div>
</div>
