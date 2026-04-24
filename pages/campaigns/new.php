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
  <div class="mb-3">
    <label class="form-label">이모지 (My Token {{my.emoji}})</label>
    <input type="text" class="form-control" name="emoji" maxlength="20"
           placeholder="예: 🎁  (공백이면 토큰 주입 건너뜀)">
    <div class="form-text">이메일 제목 앞에 삽입되는 이모지. 비워두면 토큰 주입을 건너뜁니다.</div>
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
    reward_url: f.reward_url.value, emoji: f.emoji.value.trim(),
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
