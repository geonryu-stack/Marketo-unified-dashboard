<?php
// src/Marketo/MarketoAPI.php
declare(strict_types=1);

class MarketoAPI
{
    /**
     * Marketo가 요청을 처리하기 전 게이트에서 거절한 코드 — 모든 메서드에 대해 재시도 안전.
     *  606: API rate limit (100 calls/20s)
     *  615: concurrent call limit (10 동시)
     */
    public const SAFE_RETRY_CODES = [606, 615];

    /**
     * 처리 도중/후 발생 가능 코드 — 부작용(POST/DELETE) 중복 위험 있어 GET만 재시도.
     *  502/503/504: 게이트웨이/서버 에러. 요청이 처리됐을 수도 안 됐을 수도 있음.
     */
    public const IDEMPOTENT_RETRY_CODES = [502, 503, 504];

    /** 토큰 만료 — 캐시 삭제 후 헤더 재구성하여 1회만 즉시 재시도 */
    public const TOKEN_EXPIRED_CODE = 602;

    /** 백오프 지연 (초). 길이가 곧 최대 재시도 횟수 */
    public const RETRY_DELAYS = [2, 4, 8];

    /** 토큰 캐시 파일 무효화 (race-safe: 만료 마킹). MarketoBulkImport와 공유. */
    public static function invalidateTokenCache(): void
    {
        if (defined('TOKEN_CACHE_FILE') && file_exists(TOKEN_CACHE_FILE)) {
            @unlink(TOKEN_CACHE_FILE);
        }
    }

    private static function curlRaw(string $method, string $url, array $headers, mixed $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }
        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            throw new RuntimeException('Marketo API curl 오류');
        }
        $data = json_decode($response, true) ?? [];
        // 5xx HTTP 응답이 errors 없이 통과되는 것을 차단 (retry 로직이 인지 가능하도록 표면화)
        if ($http_code >= 500 && empty($data['errors'])) {
            $data['errors'] = [['code' => $http_code, 'message' => "HTTP $http_code"]];
        }
        return $data;
    }

    /**
     * Marketo API 호출 + 자동 재시도.
     *
     * 재시도 안전성은 HTTP 메서드별로 다르게 적용된다 (POST/DELETE 부작용 중복 방지):
     *  - 602 (토큰 만료): 모든 메서드 — 처리 전 거절이라 안전. 즉시 재시도 (1회).
     *  - 606/615 (rate limit/concurrency): 모든 메서드 — 게이트에서 거절이라 안전.
     *  - 502/503/504, 네트워크 오류: GET만 재시도. POST/DELETE는 이미 서버에서 처리됐을 수
     *    있어 재시도 시 중복 부작용 위험 (예: scheduleEmailProgram 중복 예약, sendSampleEmail
     *    중복 발송). 즉시 throw하여 호출자가 인지할 수 있게 함.
     *  - 그 외 4xx 등: 즉시 throw.
     *
     * 백오프: 2s → 4s → 8s, 최대 3회.
     */
    private static function curl(string $method, string $url, array $headers = [], mixed $body = null): array
    {
        $tokenRefreshed = false;
        $is_safe_method = strtoupper($method) === 'GET';
        $max_attempts   = count(self::RETRY_DELAYS);

        for ($attempt = 0; $attempt <= $max_attempts; $attempt++) {
            try {
                $data = self::curlRaw($method, $url, $headers, $body);
            } catch (RuntimeException $e) {
                // 네트워크 오류 — GET만 재시도 (POST/DELETE는 이미 서버에 도달했을 수 있음)
                if ($is_safe_method && $attempt < $max_attempts) {
                    sleep(self::RETRY_DELAYS[$attempt]);
                    continue;
                }
                throw $e;
            }

            if (empty($data['errors'])) {
                return $data;
            }

            $code = (int)($data['errors'][0]['code'] ?? 0);
            $msg  = $data['errors'][0]['message'] ?? 'Marketo API 오류';

            // 토큰 만료 — 캐시 비우고 Authorization 헤더 즉시 재구성 (백오프 없음, 1회만)
            if ($code === self::TOKEN_EXPIRED_CODE && !$tokenRefreshed) {
                self::invalidateTokenCache();
                $headers = array_map(
                    fn($h) => str_starts_with($h, 'Authorization: Bearer ')
                        ? 'Authorization: Bearer ' . self::getAccessToken()
                        : $h,
                    $headers
                );
                $tokenRefreshed = true;
                continue;
            }

            // 게이트 거절 (606/615) — 모든 메서드 재시도 안전
            if (in_array($code, self::SAFE_RETRY_CODES, true) && $attempt < $max_attempts) {
                sleep(self::RETRY_DELAYS[$attempt]);
                continue;
            }

            // 5xx — GET만 재시도 (POST/DELETE는 부작용 중복 위험으로 즉시 throw)
            if (in_array($code, self::IDEMPOTENT_RETRY_CODES, true) && $is_safe_method && $attempt < $max_attempts) {
                sleep(self::RETRY_DELAYS[$attempt]);
                continue;
            }

            // 그 외 — 즉시 throw
            throw new RuntimeException("Marketo API 오류 (code $code): $msg");
        }

        throw new RuntimeException('Marketo API 재시도 한도 초과');
    }

    // ── 인증 토큰 ─────────────────────────────────────────────

    public static function getAccessToken(): string
    {
        // 캐시 파일에서 유효한 토큰 읽기
        if (file_exists(TOKEN_CACHE_FILE)) {
            $cache = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
            if (!empty($cache['token']) && time() < ($cache['expires_at'] ?? 0)) {
                return $cache['token'];
            }
        }

        $url = MARKETO_IDENTITY_URL . '/oauth/token?grant_type=client_credentials'
             . '&client_id=' . urlencode(MARKETO_CLIENT_ID)
             . '&client_secret=' . urlencode(MARKETO_CLIENT_SECRET);

        $data = self::curl('GET', $url);
        $token = $data['access_token'] ?? throw new RuntimeException('Marketo 토큰 발급 실패');
        $expires_in = $data['expires_in'] ?? 3600;

        // LOCK_EX로 동시 다중 워커(웹 요청 + cron)가 캐시 파일을 동시에 갱신할 때 race 차단.
        file_put_contents(TOKEN_CACHE_FILE, json_encode([
            'token'      => $token,
            'expires_at' => time() + $expires_in - 300, // 5분 여유 (장시간 배치 안전)
        ]), LOCK_EX);

        return $token;
    }

    private static function authHeaders(): array
    {
        return [
            'Authorization: Bearer ' . self::getAccessToken(),
            'Content-Type: application/json',
        ];
    }

    // ── 리드 업서트 ───────────────────────────────────────────

    /**
     * 리드를 Marketo에 upsert한다.
     * @param array $leads  string[] 또는 ['email'=>'...','country'=>'...'][] 혼용 가능
     * @return int[]  Marketo lead ID 목록
     */
    public static function upsertLeads(array $leads): array
    {
        $input = array_map(function($item) {
            if (is_string($item)) return ['email' => $item];
            $row = ['email' => $item['email']];
            if (!empty($item['country'])) $row['country'] = $item['country'];
            return $row;
        }, $leads);

        $leadIds = [];
        foreach (array_chunk($input, 300) as $chunk) {
            $body = ['action' => 'createOrUpdate', 'lookupField' => 'email', 'input' => $chunk];
            $data = self::curl('POST', MARKETO_REST_URL . '/v1/leads.json', self::authHeaders(), $body);
            foreach ($data['result'] ?? [] as $r) {
                if (!empty($r['id'])) $leadIds[] = $r['id'];
            }
        }
        return $leadIds;
    }

    // ── Static List 관리 ──────────────────────────────────────

    public static function getListLeadIds(int $listId): array
    {
        $ids  = [];
        $next = null;
        do {
            $url = MARKETO_REST_URL . "/v1/lists/$listId/leads.json?fields=id&batchSize=300"
                 . ($next ? "&nextPageToken=$next" : '');
            $data = self::curl('GET', $url, self::authHeaders());
            foreach ($data['result'] ?? [] as $r) {
                if (!empty($r['id'])) $ids[] = $r['id'];
            }
            $next = $data['nextPageToken'] ?? null;
        } while ($next);
        return $ids;
    }

    public static function addLeadsToList(int $listId, array $leadIds): void
    {
        foreach (array_chunk($leadIds, 300) as $chunk) {
            $input = array_map(fn($id) => ['id' => $id], $chunk);
            self::curl('POST', MARKETO_REST_URL . "/v1/lists/$listId/leads.json",
                self::authHeaders(), ['input' => $input]);
        }
    }

    public static function removeLeadsFromList(int $listId, array $leadIds): void
    {
        foreach (array_chunk($leadIds, 300) as $chunk) {
            self::curl('DELETE',
                MARKETO_REST_URL . "/v1/lists/$listId/leads.json?_method=DELETE",
                self::authHeaders(),
                ['input' => array_map(fn($id) => ['id' => $id], $chunk)]
            );
        }
    }

    // ── My Token 주입/삭제 ────────────────────────────────────

    /**
     * 토큰 배열을 Marketo Program의 부모 폴더에 동기화한다.
     * Marketo Email Program은 프로그램 레벨 토큰 API(POST)를 지원하지 않으므로(610 오류),
     * 프로그램 정보를 조회해 부모 폴더 ID를 얻은 뒤 폴더 레벨에서 주입한다.
     */
    public static function syncProgramMyTokens(int $programId, array $tokens): void
    {
        $program  = self::curl('GET',
            MARKETO_REST_URL . "/asset/v1/program/$programId.json",
            self::authHeaders()
        );
        $folderId = (int)(($program['result'][0]['folder']['value']) ?? 0);
        if (!$folderId) {
            throw new RuntimeException("Program $programId 의 부모 폴더 ID를 조회할 수 없습니다.");
        }
        self::syncFolderMyTokens($folderId, $tokens);
    }

    /**
     * 토큰 배열을 Marketo 폴더 레벨에 동기화한다.
     * - value 비어 있음 → DELETE (폴더 레벨 오버라이드 제거, 상위 폴더 기본값 복귀)
     * - value 있음      → POST  (생성 or 덮어쓰기)
     */
    public static function syncFolderMyTokens(int $folderId, array $tokens): void
    {
        $set_url   = MARKETO_REST_URL . "/asset/v1/folder/$folderId/tokens.json";
        $clear_url = MARKETO_REST_URL . "/asset/v1/folder/$folderId/token.json";
        $auth      = 'Authorization: Bearer ' . self::getAccessToken();
        $form_ct   = 'Content-Type: application/x-www-form-urlencoded';

        $errors = [];
        foreach ($tokens as $token) {
            $name = $token['name'];
            $type = $token['type'] ?? 'text';
            try {
                if ($token['value'] !== '') {
                    self::curl('POST', $set_url, [$auth, $form_ct],
                        http_build_query(['name' => $name, 'value' => $token['value'], 'type' => $type]));
                } else {
                    $data = self::curlRaw('DELETE', $clear_url, [$auth, $form_ct],
                        http_build_query(['name' => $name, 'type' => $type]));
                    if (!empty($data['errors'])) {
                        $code = (int)($data['errors'][0]['code'] ?? 0);
                        if ($code !== 702) {
                            $errors[] = "$name DELETE 실패 (code $code): "
                                      . ($data['errors'][0]['message'] ?? '');
                        }
                    }
                }
            } catch (RuntimeException $e) {
                $errors[] = "$name: " . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            throw new RuntimeException(
                'My Token 부분 실패 — Marketo 토큰 상태가 불일치할 수 있습니다. 실패한 토큰: '
                . implode(' / ', $errors)
            );
        }
    }

    // ── 테스트 메일 발송 ──────────────────────────────────────

    public static function sendSampleEmail(int $emailId, string $toEmail): void
    {
        // sendSample API는 JSON body 대신 URL 쿼리 파라미터로 emailAddress를 받음
        $url = MARKETO_REST_URL . "/asset/v1/email/$emailId/sendSample.json"
             . '?emailAddress=' . urlencode($toEmail) . '&textOnly=false';
        self::curl('POST', $url, ['Authorization: Bearer ' . self::getAccessToken()]);
    }

    // ── Email Program 예약/취소 ───────────────────────────────

    /**
     * @param string $datetimeUtc  'YYYY-MM-DDTHH:MM:SSZ' (ISO 8601)
     *                             RTZ ON 시: 이 시각을 각 수신자의 현지 시각으로 해석해 발송
     * @param bool   $recipientTimeZone  true = RTZ 활성화 (기본값)
     */
    public static function scheduleEmailProgram(int $programId, string $datetimeUtc, bool $recipientTimeZone = true): void
    {
        $body = ['scheduledAt' => $datetimeUtc, 'recipientTimeZone' => $recipientTimeZone];
        self::curl('POST',
            MARKETO_REST_URL . "/asset/v1/emailProgram/$programId/schedule.json",
            self::authHeaders(),
            $body
        );
    }

    public static function unapproveEmailProgram(int $programId): void
    {
        self::curl('POST',
            MARKETO_REST_URL . "/asset/v1/emailProgram/$programId/unapprove.json",
            self::authHeaders()
        );
    }

    /**
     * Email Program을 unapprove하되, 이미 draft(unapproved) 상태인 경우는 정상으로 처리.
     * 반환값: 'unapproved' | 'already_draft'
     * 그 외 Marketo 오류(인증 실패, 존재하지 않는 ID 등)는 예외로 전파.
     *
     * Marketo 오류 코드 참고:
     *   709 — Email Program이 승인 상태가 아님 (이미 draft) → 안전하게 무시
     *   그 외(702 포함) → 실제 오류이므로 예외 전파
     */
    public static function unapproveEmailProgramSafe(int $programId): string
    {
        $data = self::curlRaw('POST',
            MARKETO_REST_URL . "/asset/v1/emailProgram/$programId/unapprove.json",
            self::authHeaders()
        );
        if (empty($data['errors'])) {
            return 'unapproved';
        }
        $code = (int)($data['errors'][0]['code'] ?? 0);
        $msg  = $data['errors'][0]['message'] ?? 'Marketo API 오류';
        if ($code === 709) {
            return 'already_draft'; // 이미 unapproved 상태 — 재예약 가능
        }
        throw new RuntimeException("Email Program unapprove 실패 (code $code): $msg");
    }

    // ── 조회 API ──────────────────────────────────────────────

    public static function getEmailList(): array
    {
        $data = self::curl('GET',
            MARKETO_REST_URL . '/asset/v1/emails.json?maxReturn=200&status=approved',
            self::authHeaders()
        );
        return $data['result'] ?? [];
    }

    public static function getEmailsByProgram(int $programId): array
    {
        $folder = urlencode(json_encode(['type' => 'Program', 'id' => $programId]));
        $data = self::curl('GET',
            MARKETO_REST_URL . "/asset/v1/emails.json?folder={$folder}&maxReturn=200",
            self::authHeaders()
        );
        return $data['result'] ?? [];
    }

    public static function getStaticLists(): array
    {
        $data = self::curl('GET',
            MARKETO_REST_URL . '/asset/v1/staticLists.json?maxReturn=200',
            self::authHeaders()
        );
        return $data['result'] ?? [];
    }

    public static function getSmartCampaigns(): array
    {
        $data = self::curl('GET',
            MARKETO_REST_URL . '/rest/v1/campaigns.json?batchSize=200',
            self::authHeaders()
        );
        return $data['result'] ?? [];
    }

    // ── Activity API ──────────────────────────────────────────────

    public static function getActivityPagingToken(string $sinceIso): string
    {
        $url  = MARKETO_REST_URL . '/v1/activities/pagingtoken.json?sinceDatetime=' . urlencode($sinceIso);
        $data = self::curl('GET', $url, self::authHeaders());
        return $data['nextPageToken'] ?? throw new RuntimeException('Activity paging token 발급 실패');
    }

    /**
     * @param int    $listId   Marketo Static List ID
     * @param string $sinceIso ISO 8601 UTC e.g. '2026-04-29T01:00:00Z'
     * @param int[]  $typeIds  Activity type IDs: 6=Sent, 7=Delivered, 11=SoftBounce, 12=HardBounce
     * @return array  [['leadId'=>..., 'activityTypeId'=>6, 'activityDate'=>'...Z'], ...]
     */
    public static function getEmailActivities(int $listId, string $sinceIso, array $typeIds = [6, 7, 11, 12]): array
    {
        $token      = self::getActivityPagingToken($sinceIso);
        $typeParam  = implode(',', $typeIds);
        $activities = [];

        do {
            $url  = MARKETO_REST_URL . '/v1/activities.json'
                  . '?activityTypeIds=' . $typeParam
                  . '&listId=' . $listId
                  . '&nextPageToken=' . urlencode($token);
            $data = self::curl('GET', $url, self::authHeaders());
            foreach ($data['result'] ?? [] as $act) {
                $activities[] = $act;
            }
            $token = $data['nextPageToken'] ?? null;
            $more  = $data['moreResult']    ?? false;
        } while ($more && $token);

        return $activities;
    }

    public static function getPrograms(int $offset = 0): array
    {
        $data = self::curl('GET',
            MARKETO_REST_URL . "/asset/v1/programs.json?maxReturn=200&offset=$offset",
            self::authHeaders()
        );
        return $data['result'] ?? [];
    }

    public static function getProgramByName(string $name): array
    {
        $data = self::curl('GET',
            MARKETO_REST_URL . '/asset/v1/programs.json?maxReturn=200&name=' . urlencode($name),
            self::authHeaders()
        );
        return $data['result'] ?? [];
    }

    public static function getEmailPrograms(): array
    {
        $data = self::curl('GET',
            MARKETO_REST_URL . '/asset/v1/emailPrograms.json?maxReturn=200',
            self::authHeaders()
        );
        return $data['result'] ?? [];
    }

    public static function getProgramTokens(int $programId): array
    {
        $data = self::curl('GET',
            MARKETO_REST_URL . "/asset/v1/program/$programId/tokens.json",
            self::authHeaders()
        );
        return $data['result'] ?? [];
    }

    // ── Email Program 스냅샷 (C-SCHEDULE-ECHO / EP 미러링용) ────────
    /**
     * Email Program의 현재 상태 스냅샷.
     * 두 개의 GET API를 결합해 정제된 응답을 돌려준다 (모두 재시도 안전).
     *
     *  - GET /asset/v1/emailProgram/{id}.json          → name, status
     *  - GET /asset/v1/emailProgram/{id}/schedule.json → scheduledAt, recipientTimeZone
     *
     * schedule이 비어있는(=미예약) Email Program도 정상 동작:
     *  - scheduledAt = null
     *  - recipientTimeZone = false
     *
     * @return array{id:int, name:string, status:string, scheduledAt:?string, recipientTimeZone:bool}
     */
    public static function getEmailProgramSnapshot(int $epId): array
    {
        $program  = self::curl('GET',
            MARKETO_REST_URL . "/asset/v1/emailProgram/$epId.json",
            self::authHeaders()
        );
        $schedule = self::getEmailProgramScheduleSafe($epId);
        return self::buildEpSnapshot($epId, $program, $schedule);
    }

    /**
     * schedule API는 미예약 EP에 대해 errors를 반환할 수 있다.
     * 그 경우 'no schedule' 의미로 빈 array를 돌려주어 호출자가 안전하게 처리하도록 함.
     */
    private static function getEmailProgramScheduleSafe(int $epId): array
    {
        $data = self::curlRaw('GET',
            MARKETO_REST_URL . "/asset/v1/emailProgram/$epId/schedule.json",
            self::authHeaders()
        );
        if (!empty($data['errors'])) {
            // 미예약 / draft 상태에서 schedule 조회 시 발생할 수 있는 정상 분기
            return [];
        }
        return $data;
    }

    /**
     * EP 스냅샷 정제 — 순수 로직(테스트 가능).
     * Marketo 응답에서 필요한 필드만 추출해 안정 키로 변환한다.
     */
    public static function buildEpSnapshot(int $epId, array $program, array $schedule): array
    {
        $row = $program['result'][0] ?? [];
        $sched = $schedule['result'][0] ?? [];
        return [
            'id'                => $epId,
            'name'              => (string)($row['name']   ?? ''),
            'status'            => (string)($row['status'] ?? 'draft'),
            'scheduledAt'       => isset($sched['scheduledAt']) && $sched['scheduledAt'] !== ''
                                     ? (string)$sched['scheduledAt'] : null,
            'recipientTimeZone' => !empty($sched['recipientTimeZone']),
        ];
    }
}
