<?php
// src/Suppression.php
// VVIP 우선순위 Suppression — 전용 모듈.
//
// 단일 책임: "어느 세그먼트가 어느 세그먼트의 같은 날 발송에서 자신을 빼는가" 한 가지.
// 이 도메인의 모든 로직(파싱·검증·DB 박제·NOT IN 결합·충돌 검사)은 본 클래스에 모인다.
declare(strict_types=1);

class Suppression
{
    /**
     * 같은 calendar day 에 "현재 진행 중" 으로 간주할 campaign status 집합.
     * sent / failed / draft / cancelled 는 제외 — 이미 끝났거나 시작 전이라 suppression 효과 없음.
     * 본 상수는 충돌검사·박제조회 두 경로가 똑같이 참조 → 단일 진실(single source).
     */
    public const ACTIVE_STATES = [
        'awaiting_approval', 'scheduling', 'bulk_polling', 'bulk_finalizing',
        'scheduled', 'needs_manual_review',
    ];

    // ── 순수 헬퍼 (DB 의존 없음, 단위 테스트 용이) ────────────────

    /**
     * campaigns.send_time 문자열에서 'YYYY-MM-DD' 부분을 안전하게 추출.
     * 'YYYY-MM-DDTHH:MM' / 'YYYY-MM-DD HH:MM:SS' / 'YYYY-MM-DD' 모두 처리.
     * 파싱 불가 시 빈 문자열 — 호출자가 분기.
     */
    public static function extractSendDate(string $send_time): string
    {
        $s = trim($send_time);
        if ($s === '') return '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
            return $m[1];
        }
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : '';
    }

    /**
     * suppresses_segment_ids JSON 을 PHP 배열로 안전 디코드.
     *  - 비어있음/잘못된 JSON → 빈 배열
     *  - 비문자열·공백 항목은 제거, 순서 보존
     */
    public static function decode(?string $json): array
    {
        if ($json === null || $json === '') return [];
        $arr = json_decode($json, true);
        if (!is_array($arr)) return [];
        return array_values(array_filter(
            array_map(fn($v) => is_string($v) ? trim($v) : '', $arr),
            fn($v) => $v !== ''
        ));
    }

    /**
     * 운영자 입력을 검증해 JSON 문자열로 정규화. API POST/PUT 직전 호출용.
     *  - 자기 자신($my_id) 제외
     *  - 존재하는 segments.id 만 통과 (오타·삭제된 ID 차단)
     *  - 중복 제거
     * 검증 실패 시 json_err 로 400 응답 후 종료.
     *
     * @param mixed  $raw    배열 기대값. 그 외 타입은 빈 '[]'로 폴백.
     * @param string $my_id  본 세그먼트 ID — 자기 참조 차단용
     */
    public static function sanitizeInput($raw, string $my_id): string
    {
        if (!is_array($raw) || empty($raw)) return '[]';

        $clean = [];
        foreach ($raw as $sid) {
            if (!is_string($sid)) continue;
            $sid = trim($sid);
            if ($sid === '' || $sid === $my_id) continue;
            $clean[$sid] = true;
        }
        if (empty($clean)) return '[]';

        $ids = array_keys($clean);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $existing     = DB::all("SELECT id FROM segments WHERE id IN ($ph)", $ids);
        $existing_set = array_flip(array_column($existing, 'id'));

        $invalid = array_filter($ids, fn($i) => !isset($existing_set[$i]));
        if (!empty($invalid)) {
            // helpers.php 의 json_err 는 exit 호출 → 본 함수도 같은 분기에서 종료된다.
            json_err('suppresses_segment_ids 에 존재하지 않는 세그먼트 ID가 포함되었습니다: '
                . implode(', ', $invalid), 400);
        }
        return json_encode($ids, JSON_UNESCAPED_UNICODE);
    }

    // ── DB 의존 로직 ─────────────────────────────────────────────

    /**
     * 본 세그먼트($my_segment_id)가 발송될 때 같은 calendar day 에 활성 상태인
     * suppressor 캠페인이 박제한 이메일 목록.
     *
     * 단계:
     *   1) suppresses_segment_ids JSON 배열에 $my_segment_id 를 포함하는 segments 조회
     *   2) 그 세그먼트의 캠페인 중 같은 send_date · 활성 상태인 것의 segment_lead_suppressions 이메일 DISTINCT
     *
     * @return string[] 소문자·중복제거된 이메일 배열 (없으면 빈 배열)
     */
    public static function computeEmails(string $my_segment_id, string $send_date): array
    {
        if ($my_segment_id === '' || $send_date === '') return [];

        // Step 1 — 나를 suppress 대상으로 지정한 suppressor 세그먼트들
        // JSON_CONTAINS 의 두 번째 인자는 JSON 리터럴이어야 하므로 JSON_QUOTE 로 감싼다.
        $suppressors = DB::all(
            'SELECT id FROM segments WHERE JSON_CONTAINS(suppresses_segment_ids, JSON_QUOTE(?))',
            [$my_segment_id]
        );
        if (empty($suppressors)) return [];

        $suppressor_ids = array_column($suppressors, 'id');
        $seg_ph = implode(',', array_fill(0, count($suppressor_ids), '?'));
        $st_ph  = implode(',', array_fill(0, count(self::ACTIVE_STATES), '?'));

        // Step 2 + 3 — campaigns 와 segment_lead_suppressions JOIN. 단일 쿼리.
        $sql = "
            SELECT DISTINCT s.email
              FROM segment_lead_suppressions s
              JOIN campaigns c
                ON c.id = s.suppressor_campaign_id
             WHERE s.send_date = ?
               AND s.suppressor_segment_id IN ($seg_ph)
               AND DATE(c.send_time) = ?
               AND c.status IN ($st_ph)
        ";
        $params = array_merge([$send_date], $suppressor_ids, [$send_date], self::ACTIVE_STATES);

        $rows = DB::all($sql, $params);

        // 정규화 — 소문자·trim 으로 비교 시 대소문자 차이 누락 방지.
        $norm = [];
        foreach ($rows as $r) {
            $e = strtolower(trim((string)($r['email'] ?? '')));
            if ($e !== '') $norm[$e] = true;
        }
        return array_keys($norm);
    }

    /**
     * suppressor 캠페인의 추출 결과 이메일을 segment_lead_suppressions 에 박제.
     * DELETE→INSERT 로 재추출 idempotent.
     * suppresses_segment_ids 가 빈 세그먼트는 호출 전에 스킵해야 함 (호출자 책임).
     *
     * @param mixed[] $emails  string 또는 ['email'=>...] 혼용 가능
     */
    public static function persistPool(
        string $suppressor_campaign_id,
        string $suppressor_segment_id,
        string $send_date,
        array $emails
    ): void {
        if ($suppressor_campaign_id === '' || $send_date === '') return;

        DB::exec(
            'DELETE FROM segment_lead_suppressions WHERE suppressor_campaign_id = ?',
            [$suppressor_campaign_id]
        );

        // 정규화 + 중복 제거
        $unique = [];
        foreach ($emails as $e) {
            $addr = is_array($e) ? ($e['email'] ?? '') : $e;
            $addr = strtolower(trim((string)$addr));
            if ($addr !== '') $unique[$addr] = true;
        }
        if (empty($unique)) return;

        $now = now_str();
        // 500 청크 — VVIP < 1,000 가정이지만 안전 마진.
        foreach (array_chunk(array_keys($unique), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)'));
            $sql = 'INSERT INTO segment_lead_suppressions
                    (suppressor_segment_id, suppressor_campaign_id, send_date, email, created_at)
                    VALUES ' . $placeholders;
            $params = [];
            foreach ($chunk as $addr) {
                array_push($params,
                    $suppressor_segment_id, $suppressor_campaign_id, $send_date, $addr, $now
                );
            }
            DB::exec($sql, $params);
        }
    }

    /**
     * 캠페인 cancel / DELETE 시 박제 잔여 행 정리.
     * suppressor 였든 아니든 호출 가능 (행이 없으면 noop).
     */
    public static function clearForCampaign(string $campaign_id): void
    {
        if ($campaign_id === '') return;
        DB::exec('DELETE FROM segment_lead_suppressions WHERE suppressor_campaign_id = ?', [$campaign_id]);
    }

    /**
     * VVIP 승인 시점 충돌 검사 — 같은 calendar day 에 suppress 대상 세그먼트의
     * 캠페인이 이미 활성 상태인지 1건만 확인. 있으면 그 campaign row를 반환,
     * 없으면 null. 호출자는 응답을 HTTP 409로 매핑.
     *
     * 반대 방향(Active→VVIP)은 검사 불필요 — Active 는 추출 시 자연 제외됨.
     *
     * @param string[] $target_segment_ids  본인의 suppresses_segment_ids (decode 결과)
     * @return ?array  {id, name, segment_name} 또는 null
     */
    public static function findBlockingActiveCampaign(
        string $send_date,
        array $target_segment_ids
    ): ?array {
        if ($send_date === '' || empty($target_segment_ids)) return null;

        $seg_ph = implode(',', array_fill(0, count($target_segment_ids), '?'));
        $st_ph  = implode(',', array_fill(0, count(self::ACTIVE_STATES), '?'));
        $params = array_merge($target_segment_ids, [$send_date], self::ACTIVE_STATES);

        return DB::one(
            "SELECT id, name, segment_name FROM campaigns
              WHERE segment_id IN ($seg_ph)
                AND DATE(send_time) = ?
                AND status IN ($st_ph)
              ORDER BY id LIMIT 1",
            $params
        );
    }

    /**
     * 사내 DB SELECT의 WHERE 절에 'email NOT IN (...)' 결합.
     * 청크 단위(1,000) 로 나눠 placeholder 폭주 방지. VVIP<1,000 가정에선 단일 청크.
     * 빈 suppress_set → WHERE/params 변경 없이 그대로 반환.
     *
     * @param string   $where_sql 기존 WHERE 절
     * @param mixed[]  $params    기존 bind params
     * @param string   $email_col 백틱 포함 컬럼명 (예: '`email`')
     * @param string[] $suppress_emails 제외 대상 이메일 (소문자·정규화 가정)
     * @return array{sql: string, params: array}
     */
    public static function applyToWhereClause(
        string $where_sql,
        array $params,
        string $email_col,
        array $suppress_emails
    ): array {
        if (empty($suppress_emails)) {
            return ['sql' => $where_sql, 'params' => $params];
        }
        $clauses = [];
        foreach (array_chunk($suppress_emails, 1000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $clauses[] = "$email_col NOT IN ($ph)";
            $params = array_merge($params, $chunk);
        }
        return [
            'sql'    => '(' . $where_sql . ') AND ' . implode(' AND ', $clauses),
            'params' => $params,
        ];
    }

    /**
     * bypass 모드용 PHP-side 필터.
     * 'email|country' / 'email' 혼용 entries 를 받아 suppress 이메일 제외.
     *
     * @param string[] $bypass_entries
     * @param string[] $suppress_emails 소문자·정규화 가정
     * @return array{leads: array, skipped: int} leads는 string|array 혼용
     */
    public static function applyToBypassList(array $bypass_entries, array $suppress_emails): array
    {
        $lookup = array_flip($suppress_emails);
        $leads  = [];
        $skipped = 0;
        foreach ($bypass_entries as $entry) {
            [$email, $country] = array_pad(explode('|', $entry, 2), 2, '');
            $email   = trim($email);
            $country = trim($country);
            if (!$email) continue;
            if (isset($lookup[strtolower($email)])) {
                $skipped++;
                continue;
            }
            $leads[] = $country ? ['email' => $email, 'country' => $country] : $email;
        }
        return ['leads' => $leads, 'skipped' => $skipped];
    }
}
