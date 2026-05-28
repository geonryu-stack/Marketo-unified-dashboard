<?php
// src/Marketo/MarketoAPI.php
declare(strict_types=1);

require_once __DIR__ . '/MarketoApiUsage.php';

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

    /**
     * 백오프 지연 (초). 길이가 곧 최대 재시도 횟수.
     *
     * Marketo rate limit 윈도우 = 100 calls / 20s. 과거 `[2, 4, 8]s` (합계 14s) 는 윈도우보다
     * 짧아 burst 시 재시도 모두 같은 윈도우에서 소진 → throw → needs_manual_review 격리
     * 위험 (담당자 검수 H3). `[5, 15, 30]s` (합계 50s) 로 윈도우 1.5회 이상 커버.
     *
     * Marketo Retry-After 응답 헤더가 있으면 본 값과 max 비교해 더 긴 쪽 사용 (lastRetryAfter 참조).
     */
    public const RETRY_DELAYS = [5, 15, 30];

    /**
     * 마지막 응답에서 파싱된 Retry-After 헤더값 (초). 값 없으면 null.
     * curlRaw 가 매 호출마다 갱신, curl() 의 606/615 sleep 분기가 참조한다.
     * PHP 요청 모델상 thread-safe 하므로 static field 로 충분.
     */
    private static ?int $lastRetryAfter = null;

    /** MarketoBulkImport 같은 외부 호출자가 자체 retry 루프에서 동일한 백오프 정책을 쓰도록 노출. */
    public static function lastRetryAfter(): ?int
    {
        return self::$lastRetryAfter;
    }

    /** 토큰 캐시 파일 무효화 (race-safe: 만료 마킹). MarketoBulkImport와 공유. */
    public static function invalidateTokenCache(): void
    {
        if (defined('TOKEN_CACHE_FILE') && file_exists(TOKEN_CACHE_FILE)) {
            @unlink(TOKEN_CACHE_FILE);
        }
    }

    private static function curlRaw(string $method, string $url, array $headers, mixed $body = null): array
    {
        // 매 호출마다 Retry-After 상태 초기화 — 이전 호출의 값이 다음 분기에 새지 않도록.
        self::$lastRetryAfter = null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADER         => true, // Retry-After 헤더 캡처용
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }
        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            throw new RuntimeException('Marketo API curl 오류');
        }

        // 헤더/바디 분리 후 Retry-After 파싱 (RFC 9110: 정수 초 또는 HTTP-date. 정수만 처리)
        $header_size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header_raw  = substr($response, 0, $header_size);
        $body_raw    = substr($response, $header_size);
        if (preg_match('/^Retry-After:\s*(\d+)\s*$/im', $header_raw, $m)) {
            self::$lastRetryAfter = (int)$m[1];
        }

        $data = json_decode($body_raw, true) ?? [];
        // 5xx HTTP 응답이 errors 없이 통과되는 것을 차단 (retry 로직이 인지 가능하도록 표면화)
        if ($http_code >= 500 && empty($data['errors'])) {
            $data['errors'] = [['code' => $http_code, 'message' => "HTTP $http_code"]];
        }
        return $data;
    }

    /**
     * 백오프 sleep 시간 결정 — Marketo Retry-After 헤더가 있으면 우선 사용, 없으면 RETRY_DELAYS 사용.
     * 순수함수로 분리해 단위 테스트 가능. 응답 헤더 무시로 인한 burst 격리 위험(담당자 검수 H3) 완화.
     *
     * @param int $attempt 0부터 시작하는 시도 횟수. RETRY_DELAYS[$attempt] 가 기본 백오프.
     * @return int 실제 sleep 할 초.
     */
    public static function decideBackoffSeconds(int $attempt, ?int $retry_after_header): int
    {
        // PHP 8.5 의 end() 는 by-reference 인자 필요 → const 직접 못 받음. 로컬 복사 후 사용.
        $delays  = self::RETRY_DELAYS;
        $default = $delays[$attempt] ?? $delays[count($delays) - 1];
        if ($retry_after_header !== null && $retry_after_header > 0) {
            // Retry-After 가 있어도 *최소* 우리 기본 백오프 보장 (Marketo 가 0/1초 같이 짧게 돌려줘도 안전)
            return max($retry_after_header, $default);
        }
        return $default;
    }

    /**
     * curlRaw + 토큰 만료(602) 자동 1회 갱신 wrapper.
     *
     * curl() 의 retry 루프와 달리, 호출자가 errors 응답을 *그대로 검사* 하고 싶지만 (예: 709, 702
     * 같은 분기를 직접 처리) 토큰 만료만은 자동 갱신하고 싶을 때 사용. 8시간 이상 도는 cron/배치에서
     * curlRaw 직접 호출이 silent fail 하던 결함(담당자 검수 H2) 보강.
     *
     * 정책:
     *  - 응답에 errors[code=602] 가 있으면 invalidateTokenCache + Authorization 헤더 재구성 후 1회 재시도
     *  - 그 외 errors / 정상 응답은 그대로 반환 (호출자가 분기)
     *  - SAFE_RETRY_CODES / IDEMPOTENT_RETRY_CODES 는 처리하지 않음 (curl() 사용 권장)
     */
    private static function curlRawWithTokenRefresh(string $method, string $url, array $headers, mixed $body = null): array
    {
        $data = self::curlRaw($method, $url, $headers, $body);
        if (empty($data['errors'])) return $data;
        $code = (int)($data['errors'][0]['code'] ?? 0);
        if ($code !== self::TOKEN_EXPIRED_CODE) return $data;

        self::invalidateTokenCache();
        $refreshed = array_map(
            fn($h) => str_starts_with($h, 'Authorization: Bearer ')
                ? 'Authorization: Bearer ' . self::getAccessToken()
                : $h,
            $headers
        );
        return self::curlRaw($method, $url, $refreshed, $body);
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

        // DRY_RUN_MODE — POST/DELETE 차단. GET 은 조회만이라 정상 호출.
        // HARNESS.md §E2 킬스위치. STRATEGY S0 INFRA가 도입한 is_dry_run() 헬퍼 가드.
        if (!$is_safe_method && function_exists('is_dry_run') && is_dry_run()) {
            if (function_exists('job_log')) {
                $body_preview = is_string($body) ? substr($body, 0, 120) : (is_array($body) ? json_encode(array_slice($body, 0, 2)) : '');
                job_log("[DRY_RUN] {$method} {$url} body=" . $body_preview, null, 'marketo_api', 'info');
            }
            return ['success' => true, 'result' => [['status' => 'dry_run', 'id' => 0]]];
        }

        // PR-4 (δ) — endpoint 분류는 retry 루프 밖에서 1회만. 각 시도마다 record.
        $endpoint = MarketoApiUsage::classifyEndpoint($url, $method);

        for ($attempt = 0; $attempt <= $max_attempts; $attempt++) {
            try {
                $data = self::curlRaw($method, $url, $headers, $body);
            } catch (RuntimeException $e) {
                MarketoApiUsage::record($endpoint, true); // 네트워크 오류도 콜 카운트 + error
                // 네트워크 오류 — GET만 재시도 (POST/DELETE는 이미 서버에 도달했을 수 있음)
                if ($is_safe_method && $attempt < $max_attempts) {
                    // 네트워크 오류는 Retry-After 헤더가 없음 — 기본 백오프만.
                    sleep(self::decideBackoffSeconds($attempt, null));
                    continue;
                }
                throw $e;
            }

            if (empty($data['errors'])) {
                MarketoApiUsage::record($endpoint, false);
                return $data;
            }

            $code = (int)($data['errors'][0]['code'] ?? 0);
            $msg  = $data['errors'][0]['message'] ?? 'Marketo API 오류';
            MarketoApiUsage::record($endpoint, true); // error 응답도 콜 카운트

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

            // 게이트 거절 (606/615) — 모든 메서드 재시도 안전. Retry-After 헤더 우선.
            if (in_array($code, self::SAFE_RETRY_CODES, true) && $attempt < $max_attempts) {
                sleep(self::decideBackoffSeconds($attempt, self::$lastRetryAfter));
                continue;
            }

            // 5xx — GET만 재시도 (POST/DELETE는 부작용 중복 위험으로 즉시 throw)
            if (in_array($code, self::IDEMPOTENT_RETRY_CODES, true) && $is_safe_method && $attempt < $max_attempts) {
                sleep(self::decideBackoffSeconds($attempt, self::$lastRetryAfter));
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

    /**
     * PR-4 (α) — 대량 list 조작 시 청크 사이 페이싱.
     * 60K 발송에서 200 청크가 좁은 윈도우(100콜/20초)에 몰리는 것을 사전 분산.
     * 작은 발송(< MARKETO_API_PACE_MIN_LEADS, 기본 1000) 은 영향 없음.
     */
    public static function addLeadsToList(int $listId, array $leadIds): void
    {
        $pace_us = self::pacingMicroseconds(count($leadIds));
        foreach (array_chunk($leadIds, 300) as $i => $chunk) {
            if ($i > 0 && $pace_us > 0) usleep($pace_us);
            $input = array_map(fn($id) => ['id' => $id], $chunk);
            self::curl('POST', MARKETO_REST_URL . "/v1/lists/$listId/leads.json",
                self::authHeaders(), ['input' => $input]);
        }
    }

    public static function removeLeadsFromList(int $listId, array $leadIds): void
    {
        // Marketo "Remove Leads from List" 표준 패턴은 POST + ?_method=DELETE + JSON body.
        // HTTP DELETE + body 형태는 RFC 9110 §9.3.5 권고에 따라 일부 reverse-proxy(WAF/Apache/Nginx)에서
        // body 가 strip 되어 silent 200 응답에 *실제 멤버 제거 안 됨* 발생 가능 (담당자 검수 H1).
        // 그 경우 60K 발송의 list-refresh 단계에서 이전 leads 가 그대로 남아 새 leads 와 함께 발송 →
        // 의도하지 않은 수십만 명에 중복 발송 (SEV1 급 사고). POST 메서드로 통일.
        $pace_us = self::pacingMicroseconds(count($leadIds));
        foreach (array_chunk($leadIds, 300) as $i => $chunk) {
            if ($i > 0 && $pace_us > 0) usleep($pace_us);
            self::curl('POST',
                MARKETO_REST_URL . "/v1/lists/$listId/leads.json?_method=DELETE",
                self::authHeaders(),
                ['input' => array_map(fn($id) => ['id' => $id], $chunk)]
            );
        }
    }

    /**
     * PR-4 (α) — lead 수에 따라 청크 사이 sleep 마이크로초를 결정.
     * 순수함수, 단위 테스트 용이. config 미정의 시 0(pacing 비활성).
     *
     *  - lead 수 < MARKETO_API_PACE_MIN_LEADS → 0 (작은 발송은 그대로)
     *  - lead 수 ≥ 임계 → MARKETO_API_PACE_US (기본 150,000 = 150ms)
     */
    public static function pacingMicroseconds(int $lead_count): int
    {
        $threshold = defined('MARKETO_API_PACE_MIN_LEADS') ? (int)MARKETO_API_PACE_MIN_LEADS : 0;
        if ($threshold <= 0 || $lead_count < $threshold) return 0;
        return defined('MARKETO_API_PACE_US') ? (int)MARKETO_API_PACE_US : 0;
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
     *
     * Marketo Tokens API 필수 파라미터: name, type, value, folderType.
     * folderType 은 path 의 {id} 가 가리키는 엔티티 종류를 지정한다.
     *   - syncProgramMyTokens() 가 program 의 parent folder ID 를 조회해 본 함수를 호출 → 'Folder'.
     *   - 직접 Program 의 program-level token 으로 호출하려면 'Program' 으로 바꿔야 하나, Email Program 은
     *     program-level POST 가 610 오류를 돌려주므로 우리는 'Folder' 경로만 사용 (위 doc 의 syncProgramMyTokens 참조).
     * folderType 누락 시 400 또는 silent fail (운영자 인스턴스 차이) — Tokens spec 의 4 required 중 하나.
     */
    public static function syncFolderMyTokens(int $folderId, array $tokens, string $folderType = 'Folder'): void
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
                        http_build_query([
                            'name'       => $name,
                            'value'      => $token['value'],
                            'type'       => $type,
                            'folderType' => $folderType,
                        ]));
                } else {
                    // curlRawWithTokenRefresh — 702 ('not found') 분기를 호출자가 직접 검사하므로
                    // curl() 의 retry 루프를 못 쓰지만, 토큰 만료(602) 자동 갱신은 필요.
                    $data = self::curlRawWithTokenRefresh('DELETE', $clear_url, [$auth, $form_ct],
                        http_build_query([
                            'name'       => $name,
                            'type'       => $type,
                            'folderType' => $folderType,
                        ]));
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

    /**
     * Sprint 5 — Smart Campaign (Batch) 예약 발송.
     *
     * 운영자 Marketo 계정에 emailProgram POST 권한이 차단된 경우(610 확인) 대안 경로.
     * memory의 "Batch Smart Campaign 방식" — `/rest/v1/campaigns/{id}/schedule.json` 호출.
     *
     * Smart Campaign은 unapprove 개념이 없다. schedule 재호출 시 마지막 호출 시각으로
     * 덮어쓰기되는 것이 표준 동작 (Marketo 문서 기준).
     *
     * @param int    $campaignId  Smart Campaign ID (운영자가 segments.marketo_email_program_id 에 저장한 값)
     * @param string $datetimeIso 'YYYY-MM-DDTHH:MM:SS+0000'
     * @param array  $tokens      build_campaign_tokens() 결과. body의 input.tokens 로 전송 →
     *                            이 발송 한정으로 my.Preheader 등 동적 토큰을 폴더 상속보다
     *                            우선해서 주입. 폴더 토큰 주입(syncProgramMyTokens)이 운영자
     *                            계정에서 silent fail 하는 환경에서도 토큰이 확실히 반영됨.
     */
    public static function scheduleSmartCampaign(int $campaignId, string $datetimeIso, array $tokens = []): void
    {
        $body = ['input' => ['runAt' => $datetimeIso]];
        if (!empty($tokens)) {
            // Marketo Smart Campaign schedule API의 tokens 형식:
            //   [{"name": "{{my.Emoji}}", "value": "..."}, ...]
            // build_campaign_tokens() 결과는 name='Emoji'/'Title'/'Preheader'/'RewardUrl' 평문이므로
            // '{{my.NAME}}' 형식으로 감싸 전송.
            $body['input']['tokens'] = array_map(function ($t) {
                return [
                    'name'  => '{{my.' . $t['name'] . '}}',
                    'value' => (string)($t['value'] ?? ''),
                ];
            }, $tokens);
        }
        self::curl('POST',
            MARKETO_REST_URL . "/v1/campaigns/$campaignId/schedule.json",
            self::authHeaders(),
            $body
        );
    }

    /**
     * Smart Campaign 의 schedule 을 *먼 미래로 reschedule* 해서 실제 발송을 중지한다.
     *
     * Marketo REST API 는 scheduled Smart Campaign 을 *deactivate/cancel 하는 endpoint 가 없음*
     * (Marketo 공식 한계). schedule API 로 runAt 만 갱신 가능. 우리 시스템은 운영자가 cancel 을
     * 누르면 *실제 발송이 중지* 되어야 하므로, runAt 을 2년 -7일 후 (Marketo schedule 한도 = 2년 안)
     * 로 이동시켜 de-facto cancel.
     *
     * 운영자가 Marketo UI 에서 schedule 자체를 제거하는 것이 가장 깔끔하므로, 본 호출과 별도로
     * 호출자 (api/campaigns.php cancel 분기) 는 "Marketo UI 에서 schedule 수동 제거 필요" 를 응답에 명시한다.
     * 본 호출 단독으로도 *발송은 일어나지 않음* 이 보장된다 (안전 우선).
     *
     * @throws RuntimeException Marketo API 오류 시 — 호출자가 catch 후 사용자에게 manual cancel 안내 필요.
     */
    public static function rescheduleSmartCampaignFarFuture(int $campaignId): void
    {
        // Marketo runAt 상한 = 현재 + 2년. 안전 마진 7일 빼서 +2년 -7일.
        $far_future = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+2 years -7 days')
            ->setTime(0, 0, 0)
            ->format('Y-m-d\TH:i:s\Z');

        self::curl('POST',
            MARKETO_REST_URL . "/v1/campaigns/$campaignId/schedule.json",
            self::authHeaders(),
            ['input' => ['runAt' => $far_future]]
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
        // curlRawWithTokenRefresh — 709 ('already draft') 를 호출자가 직접 분기하므로 curl() 의
        // throw 동작을 못 쓰지만, 토큰 만료(602) 자동 갱신은 필요.
        $data = self::curlRawWithTokenRefresh('POST',
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
        // MARKETO_REST_URL 은 이미 `https://xxx.mktorest.com/rest` 로 끝나므로 `/v1/` 으로 시작.
        // 과거 본 호출만 `/rest/v1/` 로 잘못 표기되어 `.../rest/rest/v1/...` 영구 404 가 났음 (담당자 검수 C1).
        $data = self::curl('GET',
            MARKETO_REST_URL . '/v1/campaigns.json?batchSize=200',
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
     *
     * 일회성 전체 수집(getCampaignEngagement 등 실시간 조회용). cron 폴링은
     * getActivitiesPaginated 를 사용해 maxPages 캡 + token 박제로 분할.
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

    /**
     * Fix 2 (PR-2) — maxPages 캡 + resume 지원 페이징 수집.
     *
     * 한 호출에서 모든 페이지를 다 빨지 않고 $maxPages 까지만 가져온다. 도달 시
     * nextPageToken 을 함께 반환 → 호출자(cron) 가 DB 에 박제하고 다음 주기에
     * $resumeToken 으로 이어받기. Marketo Activity API 페이지당 최대 300건 →
     * 500 페이지면 약 15만 activity 의 안전 마진.
     *
     * @param int          $listId      Marketo Static List ID
     * @param string|null  $sinceIso    ISO 8601 UTC. $resumeToken 이 비어있을 때만 사용.
     * @param int[]        $typeIds     Activity type IDs (Marketo 는 한 호출당 최대 10개)
     * @param string|null  $resumeToken 이전 호출에서 박제된 nextPageToken. 있으면 sinceIso 무시.
     * @param int          $maxPages    한 호출의 최대 페이지 수. 0 이면 무제한(기존 동작과 동일).
     * @return array{
     *   activities: array,
     *   next_token: ?string,
     *   truncated: bool,
     *   pages: int
     * }
     */
    public static function getActivitiesPaginated(
        int $listId,
        ?string $sinceIso,
        array $typeIds,
        ?string $resumeToken,
        int $maxPages
    ): array {
        // resume 우선 — sinceIso 는 새 폴링 시작 시점에만 paging token 으로 변환
        $token = $resumeToken;
        if ($token === null || $token === '') {
            if ($sinceIso === null || $sinceIso === '') {
                throw new RuntimeException('getActivitiesPaginated: sinceIso 또는 resumeToken 중 하나가 필요합니다.');
            }
            $token = self::getActivityPagingToken($sinceIso);
        }

        $typeParam  = implode(',', $typeIds);
        $activities = [];
        $pages      = 0;
        $more       = true;

        while ($more && $token !== null && $token !== '') {
            $url  = MARKETO_REST_URL . '/v1/activities.json'
                  . '?activityTypeIds=' . $typeParam
                  . '&listId=' . $listId
                  . '&nextPageToken=' . urlencode($token);
            $data = self::curl('GET', $url, self::authHeaders());
            foreach ($data['result'] ?? [] as $act) {
                $activities[] = $act;
            }
            $pages++;
            $token = $data['nextPageToken'] ?? null;
            $more  = $data['moreResult']    ?? false;

            // maxPages 캡 도달 — 다음 token 을 호출자에게 돌려주고 분할
            if ($maxPages > 0 && $pages >= $maxPages && $more && $token) {
                return [
                    'activities' => $activities,
                    'next_token' => (string)$token,
                    'truncated'  => true,
                    'pages'      => $pages,
                ];
            }
        }

        return [
            'activities' => $activities,
            'next_token' => null,
            'truncated'  => false,
            'pages'      => $pages,
        ];
    }

    /**
     * Activity Type ID 매핑.
     * 아래 상수값은 Marketo "표준" 인스턴스의 system activity type IDs이며,
     * 대부분의 인스턴스에서 동일하게 동작한다. 다만 일부 인스턴스에서는 ID가
     * 다르게 발급되는 경우가 보고된 적이 있으므로(특히 Open/Click/Unsubscribe),
     * 운영 인스턴스에서 매핑이 다를 경우 config/config.php에 다음과 같이
     * 빼는 방안을 권장한다:
     *
     *   define('MARKETO_ACTIVITY_IDS', [
     *       'sent'        => 6,
     *       'delivered'   => 7,
     *       'open'        => 10,
     *       'click'       => 13,
     *       'soft_bounce' => 11,
     *       'hard_bounce' => 12,
     *       'unsubscribe' => 22,
     *   ]);
     *
     * 그리고 getCampaignEngagement() 도입부에서 MARKETO_ACTIVITY_IDS가 정의돼
     * 있으면 그 값으로 덮어쓰도록 한다(현재는 표준값으로 하드코딩).
     */
    private const ENGAGEMENT_TYPE_IDS = [
        'sent'        => 6,
        'delivered'   => 7,
        'soft_bounce' => 11,
        'hard_bounce' => 12,
        'open'        => 10,
        'click'       => 13,
        'unsubscribe' => 22,
    ];

    /**
     * ENGAGEMENT_TYPE_IDS 외부 접근용. cron 이 동일한 typeIds 를 활용하도록 통일.
     * 향후 운영자 인스턴스에서 다른 ID 매핑이 필요하면 config 의 MARKETO_ACTIVITY_IDS 로
     * 오버라이드 가능하도록 확장 여지. 현재는 표준 매핑 그대로 노출.
     */
    public static function engagementTypeIds(): array
    {
        return self::ENGAGEMENT_TYPE_IDS;
    }

    /**
     * SEV1 RCA(2026-05-22) 후속 — 발송 자산명 집합이 운영자 의도와 정확히 일치하는지 판정.
     *
     * 정책 (Codex review 반영):
     *   - 발송 자산 집합에 *의도 자산이 아닌 자산이 1건이라도 섞여 있으면 mismatch*
     *   - 의도 자산만 정확히(중복 제외) 발송 → 정상
     *   - 의도 자산이 아예 없음(모두 다른 자산) → mismatch
     *   - mixed 케이스 (의도 + 다른 자산 동시 발송) → **mismatch** ← in_array 단독으로는 못 잡음
     *
     * 빈 입력 (예: 아직 sent activity 가 안 들어옴) 은 정책상 *non-mismatch* — 호출자가 별도 분기.
     *
     * @return array{mismatch:bool, unexpected:string[]} unexpected 는 의도 자산 외 발송 자산 (DISTINCT)
     */
    public static function detectAssetNameMismatch(array $sent_asset_names, string $expected_asset): array
    {
        if (empty($sent_asset_names) || trim($expected_asset) === '') {
            return ['mismatch' => false, 'unexpected' => []];
        }
        $unexpected = array_values(array_diff($sent_asset_names, [$expected_asset]));
        return [
            'mismatch'   => !empty($unexpected),
            'unexpected' => $unexpected,
        ];
    }

    /**
     * SEV1 RCA(2026-05-22) 후속 — sent activity(typeId=6) 의 primaryAttributeValue 로
     * *실제 Marketo 가 발송한 이메일 자산 이름* 을 수집한다.
     *
     * Marketo 의 Send Email activity 스키마에서 primaryAttributeValue 는 발송된 이메일
     * 자산의 이름(예: 'Smash The Piggy') 이다. 운영자가 본 시스템 UI 에서 선택한 자산 이름
     * (campaigns.asset_name) 과 발송 후 비교하면, Smart Campaign 의 Flow 에 박힌 이메일이
     * 의도와 다른지(=본 SEV1 케이스) 사후 검증 가능.
     *
     * 순수함수 (외부 호출 없음).
     *
     * @return string[] DISTINCT 발송 자산 이름들 (빈 값 제거, 원래 대소문자 보존)
     */
    public static function extractSentEmailAssetNames(array $activities): array
    {
        $names = [];
        foreach ($activities as $a) {
            $tid = (int)($a['activityTypeId'] ?? 0);
            if ($tid !== 6) continue; // 6 = Send Email
            $name = trim((string)($a['primaryAttributeValue'] ?? ''));
            if ($name !== '') $names[$name] = true;
        }
        return array_keys($names);
    }

    /**
     * M-asset-mismatch 보강 (담당자 검수) — 본 캠페인이 보낸 자산만 추출.
     *
     * extractSentEmailAssetNames 는 *listId 윈도우의 모든 sent* 를 수집하므로, 같은 audience list 를
     * 공유하는 sibling 캠페인이 24h 안에 다른 자산으로 발송한 경우 false-positive 격리 위험이 있다.
     * 본 함수는 Marketo Send Email activity 의 attributes 에 있는 'Campaign ID' (또는 'Mailing ID',
     * 'SC ID' 변종) 가 *본 캠페인의 SC/EP ID* 와 일치하는 activity 만 카운트한다.
     *
     * 운영자 인스턴스에 따라 attribute 이름이 약간 다를 수 있어 후보 set 매칭 (case-insensitive).
     * 본 캠페인 attribute 가 *하나도 안 매칭* 되는 경우 (Marketo 가 attribute 를 안 돌려준 인스턴스 등)
     * 는 보수적으로 *전체 listId 윈도우* 로 폴백 — false-negative (격리 누락) 보다 false-positive (격리됨)
     * 가 운영자 입장에서 안전.
     *
     * @param array $activities Marketo Activity 페이로드
     * @param int   $campaign_marketo_id  본 캠페인의 marketo_email_program_id (= SC ID 또는 EP ID).
     *                                    0 또는 음수면 필터 비활성 (전체 윈도우).
     * @return string[] DISTINCT 발송 자산 이름들 (빈 값 제거, 원래 대소문자 보존)
     */
    public static function extractSentEmailAssetNamesForCampaign(array $activities, int $campaign_marketo_id): array
    {
        if ($campaign_marketo_id <= 0) return self::extractSentEmailAssetNames($activities);

        $candidate_attrs = ['campaign id', 'campaign run id', 'mailing id', 'sc id', 'smart campaign id'];
        $names = [];
        $any_match_attempted = false;

        foreach ($activities as $a) {
            $tid = (int)($a['activityTypeId'] ?? 0);
            if ($tid !== 6) continue;

            $attr_match = false;
            foreach ($a['attributes'] ?? [] as $attr) {
                $attr_name = strtolower(trim((string)($attr['name'] ?? '')));
                if (in_array($attr_name, $candidate_attrs, true)) {
                    $any_match_attempted = true;
                    if ((int)($attr['value'] ?? 0) === $campaign_marketo_id) {
                        $attr_match = true;
                        break;
                    }
                }
            }
            if (!$attr_match) continue;

            $name = trim((string)($a['primaryAttributeValue'] ?? ''));
            if ($name !== '') $names[$name] = true;
        }

        // attribute 가 한 번도 매칭되지 않았다 = Marketo 인스턴스가 'Campaign ID' attribute 를 안 줌.
        // 보수적으로 전체 윈도우 폴백 (false-negative 보다 false-positive 안전).
        if (!$any_match_attempted) {
            return self::extractSentEmailAssetNames($activities);
        }

        return array_keys($names);
    }

    /**
     * 캠페인 발송 engagement 카운트 — sent/delivered/bounce + open/click/unsubscribe.
     *
     * 내부적으로 getEmailActivities()를 호출하되 표준 4종(6/7/11/12) 외에
     * Open(10) / Click(13) / Unsubscribe(22) Activity Type ID를 추가로 폴링한다.
     * Marketo Activity API는 한 호출에서 여러 type IDs를 받을 수 있으므로
     * 추가 API 호출 비용은 없다 (paging 페이지 수만 늘어남).
     *
     * 카운트 정책 (Sprint 2):
     *  - 같은 leadId의 같은 type 중복도 dedupe 하지 않고 단순 카운트.
     *    (unique counter는 별도 ★ 작업으로 미룸 — STRATEGY.md §5 Sprint 3 이후)
     *  - 단, 'bounce' = soft_bounce + hard_bounce (합산).
     *
     * @param int    $listId   Marketo Static List ID
     * @param string $sinceIso ISO 8601 UTC, 예: '2026-05-19T01:00:00Z'
     * @return array{
     *   sent:int, delivered:int, bounce:int,
     *   soft_bounce:int, hard_bounce:int,
     *   open:int, click:int, unsubscribe:int
     * }
     */
    public static function getCampaignEngagement(int $listId, string $sinceIso): array
    {
        $typeIds    = array_values(self::ENGAGEMENT_TYPE_IDS);
        $activities = self::getEmailActivities($listId, $sinceIso, $typeIds);
        return self::tallyEngagement($activities);
    }

    /**
     * Activity 배열을 engagement 카운트로 정제 — 순수 로직(테스트 가능).
     * getCampaignEngagement()와 분리되어 있어 응답 정제 로직만 단위 테스트 가능.
     *
     * @param array $activities Marketo Activity API 응답 result 항목들
     *                          (각 항목은 'activityTypeId' 키를 가진다고 가정)
     */
    public static function tallyEngagement(array $activities): array
    {
        $ids = self::ENGAGEMENT_TYPE_IDS;
        $counts = [
            'sent'        => 0,
            'delivered'   => 0,
            'bounce'      => 0,
            'soft_bounce' => 0,
            'hard_bounce' => 0,
            'open'        => 0,
            'click'       => 0,
            'unsubscribe' => 0,
        ];

        foreach ($activities as $a) {
            $tid = (int)($a['activityTypeId'] ?? 0);
            match ($tid) {
                $ids['sent']        => $counts['sent']++,
                $ids['delivered']   => $counts['delivered']++,
                $ids['soft_bounce'] => $counts['soft_bounce']++,
                $ids['hard_bounce'] => $counts['hard_bounce']++,
                $ids['open']        => $counts['open']++,
                $ids['click']       => $counts['click']++,
                $ids['unsubscribe'] => $counts['unsubscribe']++,
                default             => null,
            };
        }
        $counts['bounce'] = $counts['soft_bounce'] + $counts['hard_bounce'];
        return $counts;
    }

    /**
     * tallyEngagement 의 시간축 그룹핑 버전 — 발송 결과 시계열(campaign_daily_stats) 누적용.
     *
     * 반환 형태: ['YYYY-MM-DD' => ['sent', 'delivered', 'bounce', 'open', 'click', 'unsubscribe'], ...]
     * activityDate 필드를 timezone 으로 파싱해 stat_date 키로 그룹핑. timezone 미지원 시
     * Marketo 가 반환한 ISO 문자열의 날짜부('YYYY-MM-DD')를 그대로 사용 (UTC 기준).
     *
     * 순수함수, 외부 호출 없음 — 단위 테스트 용이.
     */
    public static function tallyEngagementByDate(array $activities): array
    {
        $ids    = self::ENGAGEMENT_TYPE_IDS;
        $bucket = []; // stat_date => counts array
        foreach ($activities as $a) {
            $tid  = (int)($a['activityTypeId'] ?? 0);
            $iso  = (string)($a['activityDate'] ?? '');
            $date = self::extractActivityDate($iso);
            if ($date === '') continue;

            if (!isset($bucket[$date])) {
                $bucket[$date] = [
                    'sent'        => 0,
                    'delivered'   => 0,
                    'bounce'      => 0,
                    'open'        => 0,
                    'click'       => 0,
                    'unsubscribe' => 0,
                ];
            }
            match ($tid) {
                $ids['sent']        => $bucket[$date]['sent']++,
                $ids['delivered']   => $bucket[$date]['delivered']++,
                $ids['soft_bounce'], $ids['hard_bounce'] => $bucket[$date]['bounce']++,
                $ids['open']        => $bucket[$date]['open']++,
                $ids['click']       => $bucket[$date]['click']++,
                $ids['unsubscribe'] => $bucket[$date]['unsubscribe']++,
                default             => null,
            };
        }
        ksort($bucket);
        return $bucket;
    }

    /**
     * activityDate ISO 문자열에서 'YYYY-MM-DD' 부분 추출. 순수 헬퍼.
     * '2026-05-21T03:45:12Z' / '2026-05-21T03:45:12+0000' / '2026-05-21 03:45:12' 모두 처리.
     */
    public static function extractActivityDate(string $iso): string
    {
        if ($iso === '') return '';
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $iso, $m)) {
            return $m[1];
        }
        $ts = strtotime($iso);
        return $ts ? date('Y-m-d', $ts) : '';
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

    /**
     * 승인 시점 자동 검증 — 에셋명 + 토큰 4종을 Marketo API로 조회해 DB 값과 비교.
     * SEV1 RCA(2026-05-22) 후속 — 수동 체크리스트 대체.
     *
     * @param int    $programId      Marketo Program ID (Smart Campaign 또는 Email Program)
     * @param string $expectedAsset  캠페인의 asset_name (DB 저장값)
     * @param array  $expectedTokens build_campaign_tokens() 결과
     * @return array{ok: bool, warnings: string[]}
     */
    public static function verifyAssetAndTokens(int $programId, string $expectedAsset, array $expectedTokens): array
    {
        $warnings = [];
        $block = false; // true = 스케줄링 차단 필요 (fail closed)

        // 1) 토큰 검증 — getProgramTokens() 로 Marketo 실제 값 조회 후 비교.
        //    토큰은 Program 수준 속성이므로 API 로 정확히 검증 가능.
        try {
            $actualTokens = self::getProgramTokens($programId);
            $diffs = diff_campaign_tokens($expectedTokens, $actualTokens);
            if (!empty($diffs)) {
                $block = true;
                foreach ($diffs as $d) {
                    $warnings[] = "토큰 불일치: {$d}";
                }
            }
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (preg_match('/\bcode\s+610\b/', $msg) || $e->getCode() === 610) {
                // Marketo 610 "Not Authorized" — Asset API 권한 미부여 환경.
                // 테스트 메일이 안전망 역할. fail open 허용 — 경고만 표시.
                $warnings[] = '토큰 자동 검증 불가 (610 권한 제한) — 테스트 메일로 확인하세요';
            } else {
                // 네트워크 오류, 인증 만료 등 예기치 않은 실패 → fail closed.
                // 검증을 수행하지 못했으므로 안전하지 않음.
                $block = true;
                $warnings[] = "토큰 검증 실패 (API 오류) — 검증 없이 발송할 수 없습니다: {$msg}";
            }
        }

        // 2) 에셋 검증은 수동 체크리스트가 담당.
        //    Smart Campaign Flow 의 "Send Email" 스텝이 참조하는 에셋은
        //    Marketo REST API 로 조회할 수 없음 (API 한계).
        //    운영자가 체크리스트에서 Marketo UI 직접 확인.

        return [
            'ok'       => !$block,
            'warnings' => $warnings,
        ];
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
        // curlRawWithTokenRefresh — errors 를 'no schedule' 의 정상 신호로 해석하므로 curl() 의
        // throw 동작을 못 쓰지만, 토큰 만료(602) 자동 갱신은 필요. 8h+ cron 에서 schedule echo
        // 검증이 false-negative (token expired 를 'no schedule' 로 오인) 되던 결함 보강.
        $data = self::curlRawWithTokenRefresh('GET',
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
