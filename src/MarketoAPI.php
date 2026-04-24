<?php
// src/MarketoAPI.php
declare(strict_types=1);

class MarketoAPI
{
    private static function curl(string $method, string $url, array $headers = [], mixed $body = null): array
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
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Marketo API curl 오류');
        }
        $data = json_decode($response, true) ?? [];
        if (!empty($data['errors'])) {
            $msg = $data['errors'][0]['message'] ?? 'Marketo API 오류';
            throw new RuntimeException("Marketo API 오류 (HTTP $httpCode): $msg");
        }
        return $data;
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

        file_put_contents(TOKEN_CACHE_FILE, json_encode([
            'token'      => $token,
            'expires_at' => time() + $expires_in - 60, // 60초 여유
        ]));

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

    public static function upsertLeads(array $emails): array
    {
        $input = array_map(fn($e) => ['email' => $e], $emails);
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

    // ── My Token 주입 ─────────────────────────────────────────

    public static function setProgramMyTokens(int $programId, array $tokens): void
    {
        // $tokens: [['name' => '{{my.xxx}}', 'value' => '...', 'type' => 'text'], ...]
        self::curl('POST',
            MARKETO_REST_URL . "/asset/v1/program/$programId/tokens.json",
            self::authHeaders(),
            $tokens
        );
    }

    public static function buildEpTokenPayload(array $campaign): array
    {
        if (empty($campaign['emoji'])) {
            return [];
        }
        return [
            ['name' => '{{my.emoji}}', 'value' => $campaign['emoji'], 'type' => 'richText'],
        ];
    }

    // ── 테스트 메일 발송 ──────────────────────────────────────

    public static function sendSampleEmail(int $emailId, string $toEmail): void
    {
        $body = [
            'emailAddress' => $toEmail,
            'textOnly'     => false,
        ];
        self::curl('POST',
            MARKETO_REST_URL . "/asset/v1/email/$emailId/sendSample.json",
            self::authHeaders(),
            $body
        );
    }

    // ── Email Program 예약/취소 ───────────────────────────────

    public static function scheduleEmailProgram(int $programId, string $datetimeUtc): void
    {
        // datetimeUtc: 'YYYY-MM-DDTHH:MM:SS+0000' 형식
        $body = ['scheduledAt' => $datetimeUtc];
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

    // ── 조회 API ──────────────────────────────────────────────

    public static function getEmailList(): array
    {
        $data = self::curl('GET',
            MARKETO_REST_URL . '/asset/v1/emails.json?maxReturn=200&status=approved',
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
}
