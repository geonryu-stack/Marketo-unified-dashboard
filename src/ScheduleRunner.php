<?php
// src/ScheduleRunner.php — 단일 캠페인을 즉시 Marketo 예약하는 공용 함수
// cron/run_due_campaigns.php 와 api/campaigns.php(schedule-now) 모두에서 사용
declare(strict_types=1);

require_once __DIR__ . '/Marketo/MarketoBulkImport.php';
require_once __DIR__ . '/Notifier.php';

/**
 * Marketo Email Program 변경 도중 실패 시 던지는 예외.
 * EP가 unapprove/schedule 중간에 끼어 있을 가능성 있음 → Marketo 상태 불확실.
 * 이 예외가 발생한 캠페인은 status='needs_manual_review'로 자동 격리되며,
 * 같은 세그먼트의 다른 캠페인이 동일 EP를 덮어쓰지 못하도록 sibling conflict 체크에 포함.
 */
class CampaignNeedsReviewException extends RuntimeException {}

/**
 * 캠페인 한 건을 Marketo 예약 처리한다.
 *  - REST 경로 (대상자 ≤ BULK_THRESHOLD): 동기적으로 끝까지 처리 → status='scheduled'
 *  - Bulk 경로 (대상자 > BULK_THRESHOLD): CSV 비동기 업로드 → status='bulk_polling' → cron이 이어받음
 *
 * @param array    $c   campaigns 테이블의 한 행 (SELECT * 결과)
 * @param callable $log function(string $step, string $status, string $message): void
 */
function run_campaign_schedule(array $c, callable $log): void
{
    // Sprint 3 INFRA — run_id 자동 발급. 한 번의 schedule 시도를
    // 식별하는 토큰을 진입부에서 미리 만들어 후속 record_status_transition
    // 호출이 모두 동일 run_id를 받도록 보장한다. ensure_run_id 헬퍼가
    // 아직 helpers.php에 정의되지 않은 환경(예: 단위 테스트)에서는
    // 빈 run_id로 폴백한다 — sibling 차단/격리 로직과는 독립적이라 안전.
    if (function_exists('ensure_run_id')) {
        $c['run_id'] = ensure_run_id($c);
    }
    $id      = $c['id'];
    $seg     = DB::one('SELECT * FROM segments WHERE id=?', [$c['segment_id']]);
    $list_id = (int)($seg['marketo_audience_list_id'] ?? 0);
    if (!$list_id) {
        throw new RuntimeException('세그먼트에 Audience List ID 미설정');
    }

    // ── Step 1: 대상자 추출 ──────────────────────────────────────
    $leads = extract_campaign_leads($c, $seg, $log);
    if (empty($leads)) {
        throw new RuntimeException('발송 대상자가 없습니다. 세그먼트 조건 또는 BYPASS 설정을 확인하세요.');
    }

    // ── Step 2: REST or Bulk 분기 ────────────────────────────────
    $bulk_enabled   = defined('MARKETO_BULK_ENABLED') && MARKETO_BULK_ENABLED;
    $bulk_threshold = defined('BULK_THRESHOLD') ? (int)BULK_THRESHOLD : 10000;

    if ($bulk_enabled && count($leads) > $bulk_threshold) {
        run_bulk_path($c, $list_id, $leads, $log);
        return; // cron이 폴링 후 finalize_campaign_schedule 호출
    }

    run_rest_path($c, $list_id, $leads, $log);
    finalize_campaign_schedule($c, $seg, $log);
}

/**
 * 대상자 추출 — bypass 우선, 그 다음 사내 DB.
 * 반환: string[] (email만) 또는 array[] ([email, country]).
 * lead_count도 DB에 즉시 반영.
 */
function extract_campaign_leads(array $c, array $seg, callable $log): array
{
    $id = $c['id'];

    $bypass_raw = (defined('INTERNAL_DB_BYPASS_LEADS') && INTERNAL_DB_BYPASS_LEADS !== '')
        ? INTERNAL_DB_BYPASS_LEADS : '';
    $bypass = array_filter(array_map('trim', explode(',', $bypass_raw)));

    if (!empty($bypass)) {
        // 'a@b.com|South Korea, c@d.com|Japan' 형식 파싱 (country는 선택)
        $leads = [];
        foreach ($bypass as $entry) {
            [$email, $country] = array_pad(explode('|', $entry, 2), 2, '');
            $email   = trim($email);
            $country = trim($country);
            if ($email) $leads[] = $country ? ['email' => $email, 'country' => $country] : $email;
        }
        if (empty($leads)) {
            throw new RuntimeException(
                'INTERNAL_DB_BYPASS_LEADS 설정값에서 유효한 이메일 주소를 찾을 수 없습니다. ' .
                '"이메일" 또는 "이메일|국가" 형식으로 입력하세요. (예: a@b.com|South Korea)'
            );
        }
        $cnt = count($leads);
        $summary = implode(', ', array_map(fn($l) => is_array($l) ? "{$l['email']}({$l['country']})" : $l, $leads));
        $log('extract', 'done', "[우회 모드] {$cnt}명: {$summary}");
        DB::exec('UPDATE campaigns SET lead_count=?, updated_at=? WHERE id=?', [$cnt, now_str(), $id]);
        // Sprint 1 DB — C-LEAD-COUNT: segments에도 last_count 박제(드리프트 비교 기준점).
        // bypass 모드도 동일하게 적용 — 운영자가 우회 명단을 변경했을 때 추적 가능.
        DB::exec(
            'UPDATE segments SET last_count=?, last_extracted_at=? WHERE id=?',
            [$cnt, now_str(), $c['segment_id']]
        );
        return $leads;
    }

    if (defined('INTERNAL_DB_HOST') && INTERNAL_DB_HOST) {
        $log('extract', 'running', '사내 DB 대상자 추출 시작');
        $filters = json_decode($seg['filters'], true) ?? [];
        ['sql' => $where, 'params' => $params] = build_where_clause($filters, get_field_defs());
        $sql = "SELECT `" . INTERNAL_DB_EMAIL_FIELD . "` AS email FROM `" . INTERNAL_DB_TABLE . "` WHERE $where";
        assert_readonly($sql);
        $rows   = InternalDB::query($sql, $params);
        $emails = array_values(array_filter(array_column($rows, 'email')));
        $cnt    = count($emails);
        $log('extract', 'done', "추출 완료: {$cnt}명");
        DB::exec('UPDATE campaigns SET lead_count=?, updated_at=? WHERE id=?', [$cnt, now_str(), $id]);
        // Sprint 1 DB — C-LEAD-COUNT: segments에도 last_count 박제(드리프트 비교 기준점).
        // 다음 회차 추출 직전에 check_lead_count_drift()가 이 값을 기준으로 ±50% 편차를 감지.
        DB::exec(
            'UPDATE segments SET last_count=?, last_extracted_at=? WHERE id=?',
            [$cnt, now_str(), $c['segment_id']]
        );
        return $emails;
    }

    throw new RuntimeException(
        '발송 대상자를 확인할 수 없습니다. ' .
        '관리자에게 문의하거나 세그먼트 필터 조건을 확인하세요. (설정 필요: INTERNAL_DB_BYPASS_LEADS 또는 사내 DB 연결)'
    );
}

/**
 * REST 경로 — 동기적으로 leads 업서트 + Static List 갱신.
 */
function run_rest_path(array $c, int $list_id, array $leads, callable $log): void
{
    $cnt = count($leads);

    $lead_ids = MarketoAPI::upsertLeads($leads);
    if (empty($lead_ids)) {
        throw new RuntimeException(
            'Marketo 리드 업서트 결과가 0건입니다. 이메일 주소 형식을 확인하거나 Marketo API 응답을 점검하세요.'
        );
    }

    $log('list_refresh', 'running', "Static List({$list_id}) 갱신 시작");
    $existing = MarketoAPI::getListLeadIds($list_id);
    if (!empty($existing)) {
        MarketoAPI::removeLeadsFromList($list_id, $existing);
        $log('list_refresh', 'running', '기존 멤버 ' . count($existing) . '명 제거');
    }
    MarketoAPI::addLeadsToList($list_id, $lead_ids);
    $log('list_refresh', 'done', "리스트 갱신 완료: {$cnt}명");
}

/**
 * Bulk 경로 — 리스트 비우고 CSV 비동기 업로드만 수행.
 * 이후 단계(토큰+EP 예약)는 cron(check_bulk_imports.php)이 폴링해서 진행.
 */
function run_bulk_path(array $c, int $list_id, array $leads, callable $log): void
{
    $id  = $c['id'];
    $cnt = count($leads);

    $log('list_refresh', 'running', "Static List({$list_id}) 비우기 시작");
    $existing = MarketoAPI::getListLeadIds($list_id);
    if (!empty($existing)) {
        MarketoAPI::removeLeadsFromList($list_id, $existing);
        $log('list_refresh', 'running', '기존 멤버 ' . count($existing) . '명 제거');
    }

    $log('bulk_submit', 'running', "Bulk Import 제출 시작 ({$cnt}명, listId={$list_id})");
    $batchId = MarketoBulkImport::submitBulkImport($list_id, $leads);

    $now = now_str();
    DB::exec(
        "UPDATE campaigns SET
         status='bulk_polling', bulk_job_id=?, bulk_status='Importing', bulk_started_at=?, updated_at=?
         WHERE id=?",
        [$batchId, $now, $now, $id]
    );
    $log('bulk_submit', 'done', "batchId={$batchId} 제출 완료. cron이 완료를 폴링합니다.");
}

/**
 * 토큰 주입 + Email Program 예약. status='scheduled' 로 갱신.
 * REST 경로는 즉시 호출, Bulk 경로는 cron이 Bulk Complete 확인 후 호출.
 *
 * EP 변경 도중 실패 시 status='needs_manual_review' 자동 격리 + CampaignNeedsReviewException.
 * 격리 이유: unapprove/scheduleEmailProgram이 트랜잭셔널하지 않아, 응답 없이 끊긴 경우
 * Marketo 측에서 이미 처리됐을 수 있음. 'failed'로 풀면 같은 세그먼트의 다른 캠페인이
 * 같은 EP를 덮어쓸 위험.
 */
function finalize_campaign_schedule(array $c, array $seg, callable $log): void
{
    $id = $c['id'];

    // ── EP 진입 전 검증 (실패해도 EP 미변경이라 'failed'로 풀어도 안전) ──
    $send_program_id = (int)($seg['marketo_program_id'] ?? 0);
    if (!$send_program_id) {
        throw new RuntimeException('세그먼트에 Program ID 미설정 — 발송 토큰 주입 불가');
    }
    $ep_id = (int)($seg['marketo_email_program_id'] ?? 0);
    if (!$ep_id) {
        throw new RuntimeException('세그먼트에 Email Program ID 미설정');
    }

    // 토큰 주입은 Email Asset Library 폴더만 건드리므로 EP 자체에는 영향 없음 → 안전 구간
    $log('inject_tokens', 'running', "발송 Program($send_program_id) My Token 주입 시작");
    $expected_tokens = build_campaign_tokens($c);
    MarketoAPI::syncProgramMyTokens($send_program_id, $expected_tokens);
    $log('inject_tokens', 'done', '4개 토큰 주입 완료 [Emoji, Title, Preheader, RewardUrl]');

    // ── C-TOKEN-VERIFY (CRITICS.md §2 ★★★) ────────────────────
    // 주입 직후 GET으로 echo-back 검증. Marketo 폴더 동기화 race / 캐시 / 권한 문제로 인한
    // "사일런트 미반영"을 잡는다. 위험구간(EP unapprove/schedule) 진입 **전**이므로
    // throw해도 EP 상태에 영향 없음 → 일반 RuntimeException으로 'failed' 처리 가능.
    //
    // 단, Marketo 인스턴스에 따라 `getProgramTokens` 권한이 없을 수 있다 (610).
    // 권한 없음은 토큰 값 자체 문제가 아니므로 verify *skip*하고 경고만 남긴다.
    // (값 불일치는 여전히 throw — 그건 실제 사일런트 미반영)
    $log('verify_tokens', 'running', "C-TOKEN-VERIFY echo-back 시작 (Program $send_program_id)");
    try {
        $actual_tokens = MarketoAPI::getProgramTokens($send_program_id);
        $mismatches    = diff_campaign_tokens($expected_tokens, $actual_tokens);
        if (!empty($mismatches)) {
            throw new RuntimeException(
                'C-TOKEN-VERIFY 실패 — Marketo에 주입된 토큰 값이 기대값과 다릅니다. '
                . '폴더 동기화 race / 캐시 문제일 수 있습니다. 잠시 후 재시도하거나 '
                . 'Marketo UI에서 Program ' . $send_program_id . ' 의 토큰을 직접 확인하세요. '
                . '불일치: ' . implode(' | ', $mismatches)
            );
        }
        $log('verify_tokens', 'done', 'C-TOKEN-VERIFY echo-back 통과');
    } catch (RuntimeException $e) {
        // Marketo 권한 차단(610)은 graceful skip — 운영자 알림
        if (str_contains($e->getMessage(), 'code 610') || str_contains($e->getMessage(), '404')) {
            $log('verify_tokens', 'running', '권한 없음(610) — C-TOKEN-VERIFY skip. 운영자 검토 권장.');
            Notifier::slack("[C-TOKEN-VERIFY skip] Program {$send_program_id} tokens API 권한 없음 ({$e->getMessage()})", 'warn');
        } else {
            throw $e; // 값 불일치는 그대로 전파
        }
    }

    // EP 진입 직전에 marketo_email_program_id를 DB에 미리 저장.
    // 위험 구간 도중 실패해도 이 ID가 보존되어 cancel 시 unapprove 가능.
    // (저장하지 않으면: finalize 실패 → resolve-review로 'scheduled' 표시 → 나중에 cancel
    //  시도 시 EP ID 없어서 unapprove 호출 안 됨 → 실제 Marketo는 scheduled 유지 → fake cancel)
    DB::exec(
        "UPDATE campaigns SET marketo_email_program_id=?, updated_at=? WHERE id=?",
        [(string)$ep_id, now_str(), $id]
    );

    // ── EP 변경 위험 구간 ─────────────────────────────────────
    // 이 구간 진입 후 어떤 단계든 실패하면 Marketo 측 상태가 불확정.
    // → status='needs_manual_review'로 격리하여 sibling 캠페인의 EP 덮어쓰기 차단.
    //
    // Sprint 5: 운영자 Marketo 계정의 emailProgram POST 권한 차단 확인됨(610).
    // MARKETO_SEND_MODE='smart_campaign' 분기 — Smart Campaign API(/rest/v1/campaigns)로 예약.
    // 'email_program' (legacy) 모드는 다른 권한 매트릭스 환경에서 사용.
    $send_mode = defined('MARKETO_SEND_MODE') ? MARKETO_SEND_MODE : 'smart_campaign';
    try {
        $send_dt = date('Y-m-d\TH:i:s', strtotime($c['send_time'])) . 'Z';

        if ($send_mode === 'smart_campaign') {
            // Smart Campaign: unapprove 개념 없음, schedule 호출 1회로 끝(재호출시 덮어쓰기).
            // 토큰을 schedule body의 input.tokens 로 함께 전송 → 폴더 상속이 silent fail
            // 하는 운영자 권한 환경에서도 my.Preheader 등 동적 토큰이 확실히 반영됨.
            $log('schedule_ep', 'running', "Smart Campaign({$ep_id}) RTZ 예약 + 토큰 4종 inline 주입: {$send_dt}");
            MarketoAPI::scheduleSmartCampaign($ep_id, $send_dt, $expected_tokens);
        } else {
            // Email Program: unapprove(safe) + schedule 2-step.
            $unapprove_result = MarketoAPI::unapproveEmailProgramSafe($ep_id);
            $log('schedule_ep', 'running', "Email Program({$ep_id}) unapprove: {$unapprove_result}");
            $log('schedule_ep', 'running', "Email Program({$ep_id}) RTZ 예약: {$send_dt}");
            MarketoAPI::scheduleEmailProgram($ep_id, $send_dt);
        }

        // ── C-SCHEDULE-ECHO (CRITICS.md §2 ★★☆) ───────────────────
        // scheduleEmailProgram 호출 직후 GET으로 재확인.
        // Marketo가 200을 반환했지만 실제로는 예약이 반영되지 않은 silent failure를 탐지.
        //
        // 위험구간 안에서 발생하므로 실패 시 CampaignNeedsReviewException으로 격리.
        // smart_campaign 모드는 emailProgram GET 권한이 없는 환경 가정 — verify skip.
        if ($send_mode === 'email_program') {
            verify_schedule_echo($id, $ep_id, $send_dt, $log);
        }

        DB::exec(
            "UPDATE campaigns SET status='scheduled', updated_at=? WHERE id=?",
            [now_str(), $id]
        );
        // 상태 전이 적재 — from은 진입 시 상태 (scheduling | bulk_finalizing)
        $from_status = $c['status'] ?? null;
        $actor       = ($from_status === 'bulk_finalizing') ? 'cron' : 'system';
        record_status_transition(
            (string)$id,
            $from_status,
            'scheduled',
            $actor,
            "EP({$ep_id}) RTZ 예약: {$send_dt}",
            $c['run_id'] ?? null
        );
        $log('schedule_ep', 'done', "Email Program({$ep_id}) 예약 완료: {$send_dt}");
    } catch (Throwable $e) {
        $err = "EP({$ep_id}) 변경 도중 실패 — Marketo 측 상태 불확실: " . $e->getMessage()
             . ' | 운영자 조치: Marketo UI에서 EP 상태(scheduled/draft) 확인 후 수동으로 '
             . "캠페인 상태를 'scheduled' 또는 'failed'로 조정. 같은 세그먼트의 다른 캠페인은 자동 차단됨. "
             . "marketo_email_program_id={$ep_id}는 이미 DB에 저장됐으므로 'scheduled'로 표시 후 cancel 시 정상 unapprove 가능.";
        DB::exec(
            "UPDATE campaigns SET status='needs_manual_review', error_message=?, updated_at=? WHERE id=?",
            [$err, now_str(), $id]
        );
        // 상태 전이 적재 — 격리 분기
        $from_status = $c['status'] ?? null;
        $actor       = ($from_status === 'bulk_finalizing') ? 'cron' : 'system';
        record_status_transition(
            (string)$id,
            $from_status,
            'needs_manual_review',
            $actor,
            $err,
            $c['run_id'] ?? null
        );
        // Slack 알림 — throw하지 않음(본업 방해 금지). 호출 직전 throw 시점에 한번만.
        Notifier::slack("⚠️ 캠페인 [{$c['name']}] needs_manual_review — {$err}", 'critical');
        throw new CampaignNeedsReviewException($err);
    }
}

/**
 * C-SCHEDULE-ECHO (CRITICS.md §2 ★★☆) — scheduleEmailProgram 직후 echo-back.
 *
 * 기대값($send_dt)과 Marketo가 echo한 scheduledAt이 분 단위로 일치하고
 * status가 'scheduled'인지 확인. 불일치 시:
 *   - status='needs_manual_review'로 격리
 *   - CampaignNeedsReviewException throw → 호출자 catch에서 sibling 차단 유지
 *
 * 시간 비교는 절대값 60초 윈도 — Marketo가 응답 시 timezone offset(±0000)이
 * 다른 표기로 돌아올 수 있어 분 단위로 동일하면 통과시킨다.
 */
function verify_schedule_echo(int $campaign_id, int $ep_id, string $expected_send_dt, callable $log): void
{
    $log('verify_schedule', 'running', "C-SCHEDULE-ECHO 시작 (EP $ep_id, expected $expected_send_dt)");
    try {
        $snap = MarketoAPI::getEmailProgramSnapshot($ep_id);
    } catch (RuntimeException $e) {
        // emailProgram API 권한 차단(610) — 검증 skip + warn. 위험구간 안이지만
        // 검증 자체가 불가능한 환경에서 needs_manual_review 격리는 운영자에게 더 큰 부담.
        if (str_contains($e->getMessage(), 'code 610') || str_contains($e->getMessage(), '404')) {
            $log('verify_schedule', 'running', '권한 없음(610) — C-SCHEDULE-ECHO skip. Marketo UI에서 직접 확인 권장.');
            if (class_exists('Notifier')) {
                Notifier::slack("[C-SCHEDULE-ECHO skip] EP {$ep_id} snapshot API 권한 없음. Marketo UI 검증 권장: " . $e->getMessage(), 'warn');
            }
            return;
        }
        throw $e;
    }

    $actual_at = $snap['scheduledAt'] ?? null;
    $status    = $snap['status'] ?? '';

    $expected_ts = strtotime($expected_send_dt) ?: 0;
    $actual_ts   = $actual_at ? (strtotime($actual_at) ?: 0) : 0;
    $diff        = ($expected_ts && $actual_ts) ? abs($expected_ts - $actual_ts) : PHP_INT_MAX;

    $ok = ($status === 'scheduled') && ($actual_ts > 0) && ($diff <= 60);

    if (!$ok) {
        $err = "C-SCHEDULE-ECHO 실패: expected scheduledAt={$expected_send_dt} "
             . 'actual=' . ($actual_at ?? 'null')
             . " status={$status} (diff={$diff}s). "
             . 'Marketo가 예약 응답을 200으로 돌려줬지만 실제 상태가 일치하지 않습니다. '
             . 'Marketo UI에서 EP 상태와 예약 시각을 직접 확인 후 캠페인 상태를 조정하세요.';
        DB::exec(
            "UPDATE campaigns SET status='needs_manual_review', error_message=?, updated_at=? WHERE id=?",
            [$err, now_str(), $campaign_id]
        );
        $log('verify_schedule', 'error',
            "C-SCHEDULE-ECHO 실패: expected scheduledAt={$expected_send_dt} "
            . 'actual=' . ($actual_at ?? 'null') . " status={$status}");
        throw new CampaignNeedsReviewException($err);
    }

    $log('verify_schedule', 'done', 'C-SCHEDULE-ECHO 통과');
}
