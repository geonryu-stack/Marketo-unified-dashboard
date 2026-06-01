<?php
// src/SendCap.php
// 리드(이메일 주소)별 일/주/월 단위 발송 cap — 전용 모듈.
//
// 단일 책임: "이 이메일 주소가 같은 날, 7일 내, 또는 30일 내에 cap 을 초과했는가" 한 가지.
// 도메인 모든 로직(추출 시점 차단·hold/sent 박제·cancel 정리·요약) 은 본 클래스에 모인다.
// Suppression.php 의 모듈화 패턴을 그대로 따라간다.
declare(strict_types=1);

class SendCap
{
    /**
     * 본 세그먼트($my_segment_id)가 $send_date 에 발송될 때, 이미 다른 캠페인이
     * 점유(hold/sent)해서 cap 을 초과하게 만드는 이메일 주소 집합을 계산한다.
     *
     * 정책:
     *  - Transactional 세그먼트(type='transactional')는 cap 면제 → 빈 배열
     *  - 본 세그먼트의 cap_per_day / cap_per_week / cap_per_month 셋 다 0 이면 무제한 → 빈 배열
     *  - lead_send_history 에서 priority >= self.cap_priority 인 행만 카운트
     *  - 윈도우: (send_date - 29일) ~ send_date 30일
     *  - HAVING: 일 cap, 주 cap, 월 cap 중 하나라도 위반 시 차단
     *  - $exclude_hold_campaign_id — 본인의 stale hold 자기-차단 방지
     *
     * @return string[] 소문자·정규화된 이메일 배열 (없으면 빈 배열)
     */
    public static function computeBlockedEmails(
        string $my_segment_id,
        string $send_date,
        string $exclude_hold_campaign_id = ''
    ): array {
        if ($my_segment_id === '' || $send_date === '') return [];

        $seg = DB::one(
            'SELECT type, cap_per_day, cap_per_week, cap_per_month, cap_priority FROM segments WHERE id=?',
            [$my_segment_id]
        );
        if (!$seg) return [];

        // Transactional 세그먼트는 frequency cap 면제 (IMPROVEMENT_SPEC #4)
        if (($seg['type'] ?? '') === 'transactional') return [];

        $cap_day   = (int)$seg['cap_per_day'];
        $cap_week  = (int)$seg['cap_per_week'];
        $cap_month = (int)($seg['cap_per_month'] ?? 0);
        $priority  = (int)$seg['cap_priority'];
        if ($cap_day === 0 && $cap_week === 0 && $cap_month === 0) return [];

        $week_start  = date('Y-m-d', strtotime($send_date . ' -6 days'));
        $month_start = date('Y-m-d', strtotime($send_date . ' -29 days'));

        // cap=0 인 경우 해당 단위 검사 skip — HAVING 절에서 절대 트리거되지 않게 큰 값 사용.
        $effective_day   = $cap_day   > 0 ? $cap_day   : PHP_INT_MAX;
        $effective_week  = $cap_week  > 0 ? $cap_week  : PHP_INT_MAX;
        $effective_month = $cap_month > 0 ? $cap_month : PHP_INT_MAX;

        $params = [$month_start, $send_date, $priority];
        $exclude_clause = '';
        if ($exclude_hold_campaign_id !== '') {
            $exclude_clause = " AND NOT (campaign_id = ? AND state = 'hold')";
            $params[] = $exclude_hold_campaign_id;
        }
        array_push($params, $send_date, $effective_day, $week_start, $effective_week, $effective_month);

        $sql = "
            SELECT email
              FROM lead_send_history
             WHERE send_date BETWEEN ? AND ?
               AND priority >= ?
               $exclude_clause
             GROUP BY email
            HAVING SUM(send_date = ?) >= ?
                OR SUM(send_date >= ?) >= ?
                OR COUNT(*) >= ?
        ";
        $rows = DB::all($sql, $params);

        $norm = [];
        foreach ($rows as $r) {
            $e = strtolower(trim((string)($r['email'] ?? '')));
            if ($e !== '') $norm[$e] = true;
        }
        return array_keys($norm);
    }

    /**
     * 추출 직후 호출. 추출된 이메일 집합을 lead_send_history 에 'hold' 박제.
     *  - 같은 (email, send_date, campaign_id) 조합은 멱등 (ON DUPLICATE KEY UPDATE priority)
     *  - 빈 입력은 noop
     *  - 청크 단위 500 — 안전 마진
     *
     * @param mixed[] $emails  string 또는 ['email'=>...] 혼용 허용 (Suppression 패턴 호환)
     */
    public static function persistHold(
        string $campaign_id,
        string $segment_id,
        string $send_date,
        int $priority,
        array $emails
    ): void {
        if ($campaign_id === '' || $send_date === '') return;

        $unique = [];
        foreach ($emails as $e) {
            $addr = is_array($e) ? ($e['email'] ?? '') : $e;
            $addr = strtolower(trim((string)$addr));
            if ($addr !== '') $unique[$addr] = true;
        }
        if (empty($unique)) return;

        $now = now_str();
        foreach (array_chunk(array_keys($unique), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,NULL)'));
            $sql = 'INSERT INTO lead_send_history
                    (email, send_date, campaign_id, segment_id, priority, state, created_at, confirmed_at)
                    VALUES ' . $placeholders . '
                    ON DUPLICATE KEY UPDATE priority = VALUES(priority)';
            $params = [];
            foreach ($chunk as $addr) {
                array_push($params,
                    $addr, $send_date, $campaign_id, $segment_id, $priority, 'hold', $now
                );
            }
            DB::exec($sql, $params);
        }
    }

    /**
     * 업서트 결과로 받은 leadIds 와 emails 를 1:1 매칭해 lead_id 컬럼을 채운다.
     * REST 경로에서 호출 (Bulk 는 leadId 매칭이 비싸서 v1 에서는 NULL 유지).
     */
    public static function attachLeadIds(string $campaign_id, array $emails, array $lead_ids): void
    {
        if ($campaign_id === '') return;
        $n = min(count($emails), count($lead_ids));
        if ($n === 0) return;

        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $e   = strtolower(trim((string)$emails[$i]));
            $lid = (int)$lead_ids[$i];
            if ($e === '' || $lid <= 0) continue;
            $rows[] = [$e, $lid];
        }
        if (empty($rows)) return;

        foreach (array_chunk($rows, 500) as $chunk) {
            $when_parts = [];
            $emails_in  = [];
            $params     = [];
            foreach ($chunk as [$e, $lid]) {
                $when_parts[] = 'WHEN ? THEN ?';
                array_push($params, $e, $lid);
                $emails_in[] = $e;
            }
            $in_ph    = implode(',', array_fill(0, count($emails_in), '?'));
            $when_sql = implode(' ', $when_parts);
            // M5: ELSE lead_id 방어 — unmatched email이 NULL로 덮어써지는 것을 방지
            $sql = "UPDATE lead_send_history
                       SET lead_id = CASE email $when_sql ELSE lead_id END
                     WHERE campaign_id = ?
                       AND email IN ($in_ph)";
            $params[] = $campaign_id;
            $params   = array_merge($params, $emails_in);
            DB::exec($sql, $params);
        }
    }

    /**
     * Marketo Activity API 가 가져온 sent activity 의 leadId 또는 email 로 매칭해 state='sent'.
     * 멱등: 이미 sent 인 행은 confirmed_at 만 갱신.
     */
    public static function confirmSent(string $campaign_id, array $lead_ids, array $emails = []): void
    {
        if ($campaign_id === '') return;

        $now = now_str();

        // 1) lead_id 로 1차 매칭
        $lid_clean = array_values(array_unique(array_filter(array_map('intval', $lead_ids), fn($v) => $v > 0)));
        foreach (array_chunk($lid_clean, 1000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $params = array_merge([$now, $campaign_id], $chunk);
            DB::exec(
                "UPDATE lead_send_history
                    SET state='sent', confirmed_at=?
                  WHERE campaign_id=? AND lead_id IN ($ph)",
                $params
            );
        }

        // 2) email 로 fallback 매칭 (lead_id NULL 인 Bulk 경로 row 가 대상)
        $em_clean = [];
        foreach ($emails as $e) {
            $addr = strtolower(trim((string)$e));
            if ($addr !== '') $em_clean[$addr] = true;
        }
        $em_clean = array_keys($em_clean);
        foreach (array_chunk($em_clean, 1000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $params = array_merge([$now, $campaign_id], $chunk);
            DB::exec(
                "UPDATE lead_send_history
                    SET state='sent', confirmed_at=?
                  WHERE campaign_id=? AND email IN ($ph) AND state='hold'",
                $params
            );
        }
    }

    /**
     * 캠페인 cancel / fail / delete 시 hold 행 정리.
     * state='sent' 는 보존(이미 발송 사실이라 cap 윈도우 안에서 카운트돼야 함).
     */
    public static function clearForCampaign(string $campaign_id): void
    {
        if ($campaign_id === '') return;
        DB::exec(
            "DELETE FROM lead_send_history WHERE campaign_id=? AND state='hold'",
            [$campaign_id]
        );
    }

    /**
     * 오래된 행 정리. cron/cleanup_lead_send_history.php 에서 호출.
     * 월간 cap 윈도우(30일)를 안전하게 커버하기 위해 기본 45일, 최소 31일.
     * @return int affected rows
     */
    public static function purgeOlderThan(int $days = 45): int
    {
        if ($days < 31) $days = 31; // 안전 가드 — 월간 cap 윈도우 30일 + 1일 마진
        return DB::exec(
            'DELETE FROM lead_send_history WHERE send_date < CURDATE() - INTERVAL ? DAY',
            [$days]
        );
    }

    /**
     * 캠페인 상세 페이지의 cap 영향 박스용 요약.
     */
    public static function summaryForCampaign(
        string $campaign_id,
        string $segment_id,
        string $send_date
    ): array {
        $seg = DB::one(
            'SELECT cap_per_day, cap_per_week, cap_per_month, cap_priority FROM segments WHERE id=?',
            [$segment_id]
        ) ?? ['cap_per_day' => 0, 'cap_per_week' => 0, 'cap_per_month' => 0, 'cap_priority' => 0];

        $counts = DB::one(
            "SELECT
                COALESCE(SUM(state='hold'),0) AS hold_cnt,
                COALESCE(SUM(state='sent'),0) AS sent_cnt
             FROM lead_send_history
             WHERE campaign_id=?",
            [$campaign_id]
        ) ?? ['hold_cnt' => 0, 'sent_cnt' => 0];

        $blocked = $send_date !== ''
            ? count(self::computeBlockedEmails($segment_id, $send_date, $campaign_id))
            : 0;

        return [
            'cap_per_day'      => (int)$seg['cap_per_day'],
            'cap_per_week'     => (int)$seg['cap_per_week'],
            'cap_per_month'    => (int)$seg['cap_per_month'],
            'cap_priority'     => (int)$seg['cap_priority'],
            'hold'             => (int)$counts['hold_cnt'],
            'sent'             => (int)$counts['sent_cnt'],
            'blocked_estimate' => $blocked,
        ];
    }

    /**
     * Marketo Send Email activity 페이로드에서 sent 만 골라 (leadId, email) pair 추출.
     */
    public static function extractSentTargets(array $activities): array
    {
        $lead_ids = [];
        $emails   = [];
        foreach ($activities as $a) {
            $tid = (int)($a['activityTypeId'] ?? 0);
            if ($tid !== 6) continue; // 6 = Send Email
            $lid = (int)($a['leadId'] ?? 0);
            if ($lid > 0) $lead_ids[$lid] = true;

            foreach ($a['attributes'] ?? [] as $attr) {
                $name = strtolower((string)($attr['name'] ?? ''));
                if (in_array($name, ['recipient', 'email address', 'email', 'lead email'], true)) {
                    $val = strtolower(trim((string)($attr['value'] ?? '')));
                    if ($val !== '' && filter_var($val, FILTER_VALIDATE_EMAIL)) {
                        $emails[$val] = true;
                    }
                }
            }
        }
        return [
            'lead_ids' => array_keys($lead_ids),
            'emails'   => array_keys($emails),
        ];
    }
}
