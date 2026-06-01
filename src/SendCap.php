<?php
// src/SendCap.php
// 리드(이메일 주소)별 일/주 단위 발송 cap — 전용 모듈.
//
// 단일 책임: "이 이메일 주소가 같은 날 또는 7일 내에 cap 을 초과했는가" 한 가지.
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
     *  - 본 세그먼트의 cap_per_day / cap_per_week 둘 다 0 이면 무제한 → 빈 배열
     *  - lead_send_history 에서 priority >= self.cap_priority 인 행만 카운트
     *    (= 본 세그먼트보다 같거나 높은 우선순위의 점유만 카운트. 낮은 priority 는 무시.)
     *  - 윈도우: (send_date - 6일) ~ send_date 7일
     *  - HAVING: 일 cap 또는 주 cap 위반 시 차단
     *  - $exclude_hold_campaign_id 가 비어있지 않으면 *그 campaign_id 의 hold 행만* 카운트에서 제외.
     *    추출 재시도(편집 후 재추출, 발송 실패 후 재시도) 시 본인의 stale hold 가 잡혀
     *    자기-차단되는 케이스를 막는 방어선.
     *  - 본인의 sent 행은 *제외하지 않는다* — 이미 발송된 사실은 cap 윈도우 안에서 본인 카운트에도
     *    포함돼야 하기 때문 (자기 자신을 7일 내 cap_per_week 회 이상 보내면 본인도 차단되는 게 정상 정책).
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
            'SELECT cap_per_day, cap_per_week, cap_priority FROM segments WHERE id=?',
            [$my_segment_id]
        );
        if (!$seg) return [];

        $cap_day  = (int)$seg['cap_per_day'];
        $cap_week = (int)$seg['cap_per_week'];
        $priority = (int)$seg['cap_priority'];
        if ($cap_day === 0 && $cap_week === 0) return [];

        $week_start = date('Y-m-d', strtotime($send_date . ' -6 days'));

        // cap_per_day=0 인 경우 일 cap 검사 skip — HAVING 분기에서 day 조건이 절대 트리거되지 않게 큰 값 사용.
        // cap_per_week=0 동일.
        $effective_day  = $cap_day  > 0 ? $cap_day  : PHP_INT_MAX;
        $effective_week = $cap_week > 0 ? $cap_week : PHP_INT_MAX;

        $params = [$week_start, $send_date, $priority];
        $exclude_clause = '';
        if ($exclude_hold_campaign_id !== '') {
            // 본인의 hold 만 제외. sent 는 그대로 카운트 (실제 발송 사실 보존).
            $exclude_clause = " AND NOT (campaign_id = ? AND state = 'hold')";
            $params[] = $exclude_hold_campaign_id;
        }
        array_push($params, $send_date, $effective_day, $effective_week);

        $sql = "
            SELECT email
              FROM lead_send_history
             WHERE send_date BETWEEN ? AND ?
               AND priority >= ?
               $exclude_clause
             GROUP BY email
            HAVING SUM(send_date = ?) >= ?
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
     *  - 같은 (email, send_date, campaign_id) 조합은 멱등 (ON DUPLICATE KEY UPDATE created_at, priority)
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
            // ON DUPLICATE: 같은 (email,send_date,campaign_id) 재추출 시 priority 만 갱신.
            // state 는 'sent' 가 우선이므로 덮어쓰지 않음 (사후 confirm 보존).
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
     * email 순서와 leadIds 순서가 동일하다는 가정 — ScheduleRunner.upsertLeads 가 그렇게 반환.
     *
     * @param string $campaign_id
     * @param string[] $emails    소문자 정규화 가정. 빈 항목 자동 skip.
     * @param int[]    $lead_ids  $emails 와 같은 길이여야 함.
     */
    public static function attachLeadIds(string $campaign_id, array $emails, array $lead_ids): void
    {
        if ($campaign_id === '') return;
        $n = min(count($emails), count($lead_ids));
        if ($n === 0) return;

        // 단일 UPDATE 로 묶는 대신 청크별 batch UPDATE — 500 청크
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $e   = strtolower(trim((string)$emails[$i]));
            $lid = (int)$lead_ids[$i];
            if ($e === '' || $lid <= 0) continue;
            $rows[] = [$e, $lid];
        }
        if (empty($rows)) return;

        foreach (array_chunk($rows, 500) as $chunk) {
            // CASE WHEN ... THEN ... 으로 batch UPDATE
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
     *
     * @param string $campaign_id
     * @param int[]  $lead_ids       sent activity 의 leadId 배열 (우선 매칭)
     * @param string[] $emails       leadId 매칭 실패 시 fallback (Activity 가 lead 본체 email 노출 시)
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
     * Suppression::clearForCampaign 패턴과 동일.
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
     * 30일 초과 행 정리. cron/cleanup_lead_send_history.php 에서 호출.
     * @return int affected rows
     */
    public static function purgeOlderThan(int $days = 30): int
    {
        if ($days < 7) $days = 7; // 안전 가드 — cap 윈도우 최소
        return DB::exec(
            'DELETE FROM lead_send_history WHERE send_date < CURDATE() - INTERVAL ? DAY',
            [$days]
        );
    }

    /**
     * 캠페인 상세 페이지의 cap 영향 박스용 요약.
     * @return array{
     *   cap_per_day:int, cap_per_week:int, cap_priority:int,
     *   hold:int, sent:int, blocked_estimate:int
     * }
     */
    public static function summaryForCampaign(
        string $campaign_id,
        string $segment_id,
        string $send_date
    ): array {
        $seg = DB::one(
            'SELECT cap_per_day, cap_per_week, cap_priority FROM segments WHERE id=?',
            [$segment_id]
        ) ?? ['cap_per_day' => 0, 'cap_per_week' => 0, 'cap_priority' => 0];

        $counts = DB::one(
            "SELECT
                COALESCE(SUM(state='hold'),0) AS hold_cnt,
                COALESCE(SUM(state='sent'),0) AS sent_cnt
             FROM lead_send_history
             WHERE campaign_id=?",
            [$campaign_id]
        ) ?? ['hold_cnt' => 0, 'sent_cnt' => 0];

        // 본 캠페인의 hold 만 제외 (자기-차단 방지). 본 캠페인이 이미 sent 한 행은
        // cap 위반 카운트에 그대로 포함되어야 한다 — 같은 캠페인이 재추출되든 다음 회차든
        // 이미 발송된 사실은 윈도우 내 cap 에 반영되는 게 정상.
        $blocked = $send_date !== ''
            ? count(self::computeBlockedEmails($segment_id, $send_date, $campaign_id))
            : 0;

        return [
            'cap_per_day'      => (int)$seg['cap_per_day'],
            'cap_per_week'     => (int)$seg['cap_per_week'],
            'cap_priority'     => (int)$seg['cap_priority'],
            'hold'             => (int)$counts['hold_cnt'],
            'sent'             => (int)$counts['sent_cnt'],
            'blocked_estimate' => $blocked,
        ];
    }

    /**
     * Marketo Send Email activity 페이로드에서 sent 만 골라 (leadId, email) pair 추출.
     * sent activity 는 typeId=6. attributes 에서 'Recipient' / 'Email Address' / 'Email' 이름이면 이메일 후보로 사용.
     *
     * Marketo 표준 인스턴스에서 sent activity 의 attributes 에는 보통 이메일이 포함되지 않고
     * leadId 만 노출되므로, email 은 Activity API 가 어쩌다 함께 줄 때만 채워진다.
     * → 호출자는 leadId 우선, email 은 fallback.
     *
     * @param array $activities Marketo Activity 페이로드
     * @return array{lead_ids:int[], emails:string[]}
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
