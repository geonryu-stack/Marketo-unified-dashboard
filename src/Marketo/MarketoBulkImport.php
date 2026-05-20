<?php
// src/Marketo/MarketoBulkImport.php
// Marketo Bulk Lead Import API 클라이언트.
// 50K 같은 대용량 발송 시 REST 다건 호출 대신 CSV 1콜로 처리.
declare(strict_types=1);

require_once __DIR__ . '/MarketoAPI.php';

class MarketoBulkImport
{
    // 재시도 분류 상수는 MarketoAPI에서 공유. 중복 정의는 분류 정책이 두 곳에 분기되는 위험.

    /**
     * 대상자를 CSV로 일괄 업로드 + Static List 멤버로 등록.
     * 비동기 작업이므로 batchId 반환 후 status 폴링 필요.
     *
     * @param int   $listId  Marketo Static List ID
     * @param array $leads   string[] 또는 ['email'=>..,'country'=>..][] 혼용
     * @return string batchId
     */
    public static function submitBulkImport(int $listId, array $leads): string
    {
        if (empty($leads)) {
            throw new RuntimeException('Bulk Import: leads 배열이 비어있습니다.');
        }
        if ($listId <= 0) {
            throw new RuntimeException('Bulk Import: listId가 유효하지 않습니다.');
        }

        $csv  = self::buildCsv($leads);
        $url  = MARKETO_REST_URL . '/bulk/v1/leads.json'
              . '?format=csv&lookupField=email&listId=' . $listId;

        // POST = 부작용 있음. 재시도는 SAFE_RETRY_CODES(606/615)와 토큰 만료(602)만.
        // 5xx/네트워크 오류는 즉시 throw하여 중복 잡 생성 방지.
        $token          = MarketoAPI::getAccessToken();
        $tokenRefreshed = false;
        $max_attempts   = count(MarketoAPI::RETRY_DELAYS);

        for ($attempt = 0; $attempt <= $max_attempts; $attempt++) {
            // CURLStringFile로 메모리 상의 CSV를 multipart 업로드 (임시 파일 불필요)
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,  // 50K CSV 업로드는 통상 30초 이내
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_POSTFIELDS     => [
                    'file'   => new CURLStringFile($csv, 'leads.csv', 'text/csv'),
                    'format' => 'csv',
                ],
            ]);
            $response  = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false) {
                // 네트워크 오류 — POST이므로 즉시 throw (중복 업로드 방지)
                throw new RuntimeException('Bulk Import POST 네트워크 오류 — Marketo 서버에 도달했을 수 있어 재시도하지 않습니다.');
            }

            $data = json_decode($response, true) ?? [];

            // 5xx HTTP — POST이므로 즉시 throw
            if ($http_code >= 500) {
                throw new RuntimeException("Bulk Import HTTP $http_code — 서버에서 처리됐을 수 있어 재시도하지 않습니다. Marketo UI에서 잡 상태를 확인하세요.");
            }

            if (!empty($data['errors'])) {
                $code = (int)($data['errors'][0]['code'] ?? 0);
                $msg  = $data['errors'][0]['message'] ?? 'Bulk Import 오류';

                // 토큰 만료 — 재발급 후 즉시 재시도 (1회)
                if ($code === MarketoAPI::TOKEN_EXPIRED_CODE && !$tokenRefreshed) {
                    MarketoAPI::invalidateTokenCache();
                    $token = MarketoAPI::getAccessToken();
                    $tokenRefreshed = true;
                    continue;
                }

                // 게이트 거절 (606/615) — 백오프 재시도
                if (in_array($code, MarketoAPI::SAFE_RETRY_CODES, true) && $attempt < $max_attempts) {
                    sleep(MarketoAPI::RETRY_DELAYS[$attempt]);
                    continue;
                }

                throw new RuntimeException("Bulk Import 오류 (code $code): $msg");
            }

            $batchId = $data['result'][0]['batchId'] ?? null;
            if (!$batchId) {
                throw new RuntimeException('Bulk Import 응답에 batchId가 없습니다.');
            }
            return (string)$batchId;
        }

        throw new RuntimeException('Bulk Import 재시도 한도 초과');
    }

    /**
     * Bulk Import 잡 상태 조회 (GET — 폴링용).
     *
     * @return array ['status' => 'Importing|Complete|Failed', 'numOfRowsWithError' => ..., ...]
     */
    public static function getBulkImportStatus(string $batchId): array
    {
        if ($batchId === '') {
            throw new RuntimeException('batchId가 비어있습니다.');
        }
        $url = MARKETO_REST_URL . "/bulk/v1/leads/batch/" . urlencode($batchId) . "/status.json";

        // GET이므로 5xx/네트워크 포함 풀 재시도 안전
        $token          = MarketoAPI::getAccessToken();
        $tokenRefreshed = false;
        $max_attempts   = count(MarketoAPI::RETRY_DELAYS);

        for ($attempt = 0; $attempt <= $max_attempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CUSTOMREQUEST  => 'GET',
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                ],
            ]);
            $response  = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // 네트워크 오류 — GET이므로 재시도
            if ($response === false) {
                if ($attempt < $max_attempts) {
                    sleep(MarketoAPI::RETRY_DELAYS[$attempt]);
                    continue;
                }
                throw new RuntimeException('Bulk Import status 네트워크 오류 (재시도 한도 초과)');
            }

            $data = json_decode($response, true) ?? [];

            // 5xx — GET이므로 재시도
            if ($http_code >= 500) {
                if ($attempt < $max_attempts) {
                    sleep(MarketoAPI::RETRY_DELAYS[$attempt]);
                    continue;
                }
                throw new RuntimeException("Bulk Import status HTTP $http_code (재시도 한도 초과)");
            }

            if (!empty($data['errors'])) {
                $code = (int)($data['errors'][0]['code'] ?? 0);
                $msg  = $data['errors'][0]['message'] ?? 'Bulk Import status 오류';

                if ($code === MarketoAPI::TOKEN_EXPIRED_CODE && !$tokenRefreshed) {
                    MarketoAPI::invalidateTokenCache();
                    $token = MarketoAPI::getAccessToken();
                    $tokenRefreshed = true;
                    continue;
                }

                if (in_array($code, MarketoAPI::SAFE_RETRY_CODES, true) && $attempt < $max_attempts) {
                    sleep(MarketoAPI::RETRY_DELAYS[$attempt]);
                    continue;
                }

                throw new RuntimeException("Bulk Import status 오류 (code $code): $msg");
            }

            $result = $data['result'][0] ?? null;
            if (!$result) {
                throw new RuntimeException('Bulk Import status 응답이 비어있습니다.');
            }
            return $result;
        }

        throw new RuntimeException('Bulk Import status 재시도 한도 초과');
    }

    /**
     * leads 배열 → CSV 문자열.
     * 헤더: email,country (country 필드 누락 시 빈 칸).
     */
    private static function buildCsv(array $leads): string
    {
        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            throw new RuntimeException('CSV 생성을 위한 임시 스트림 생성 실패');
        }
        // RFC 4180 표준에 맞춰 fputcsv가 자동 escape 처리
        fputcsv($fp, ['email', 'country']);
        foreach ($leads as $item) {
            if (is_string($item)) {
                fputcsv($fp, [$item, '']);
            } else {
                fputcsv($fp, [$item['email'] ?? '', $item['country'] ?? '']);
            }
        }
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);
        if ($csv === false) {
            throw new RuntimeException('CSV 생성 실패');
        }
        return $csv;
    }
}
