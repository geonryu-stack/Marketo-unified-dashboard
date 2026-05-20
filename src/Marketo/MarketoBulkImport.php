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

        // DRY_RUN_MODE 가드 (HARNESS §E2). 가짜 batchId 반환 → cron 폴링이 미존재 batchId로 fail 처리.
        if (function_exists('is_dry_run') && is_dry_run()) {
            $fake = 'dry-run-' . bin2hex(random_bytes(8));
            if (function_exists('job_log')) {
                job_log("[DRY_RUN] Bulk submit listId={$listId} leads=" . count($leads) . " → fake batchId={$fake}", null, 'bulk_import', 'info');
            }
            return $fake;
        }

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
     * Bulk Import 진행률 계산 (순수 함수, 외부 호출 없음).
     *
     * Sprint 3 MKT ⑭ — getBulkImportStatus() 응답을 받아 진행률·ETA·rows/sec 산출.
     * 폴링 cron(check_bulk_imports.php)에서 UI 노출용 메타데이터 적재 시 사용.
     *
     * Marketo `getBulkImportStatus` 응답 키는 인스턴스/시점에 따라 다를 수 있어
     * 다음 키 순서로 fallback 한다 (없으면 0):
     *   - processed: numOfRowsProcessed → numOfRowsCompleted
     *   - total:     numOfRowsTotal     → numOfRows
     *   - failed:    numOfRowsFailed
     *
     * @param array       $status_response getBulkImportStatus() 의 raw 반환값
     *                                     (status, numOfRowsProcessed, ...)
     * @param string|null $started_at      campaigns.bulk_started_at (DB datetime 문자열)
     *                                     null이면 elapsed_sec / rows_per_sec / eta_sec 산출 불가
     * @return array{
     *   status: string,
     *   processed: int,
     *   total: int,
     *   failed: int,
     *   progress_pct: float,
     *   rows_per_sec: float|null,
     *   eta_sec: int|null,
     *   elapsed_sec: int|null
     * }
     */
    public static function computeProgress(array $status_response, ?string $started_at = null): array
    {
        $status = (string)($status_response['status'] ?? 'Unknown');

        // processed / total / failed — 키 fallback
        $processed = (int)(
            $status_response['numOfRowsProcessed']
            ?? $status_response['numOfRowsCompleted']
            ?? 0
        );
        $total = (int)(
            $status_response['numOfRowsTotal']
            ?? $status_response['numOfRows']
            ?? 0
        );
        $failed = (int)($status_response['numOfRowsFailed'] ?? 0);

        // progress_pct — total=0이면 0 (Marketo가 total 안 주는 케이스)
        // Complete 상태인데 total=0이면 100으로 끌어올리지 않는다 (입력 데이터만으로 단정 불가)
        $progress_pct = 0.0;
        if ($total > 0) {
            $progress_pct = ($processed / $total) * 100.0;
            // clamp [0, 100] — Marketo가 processed > total 보내는 엣지 케이스 방어
            if ($progress_pct > 100.0) {
                $progress_pct = 100.0;
            } elseif ($progress_pct < 0.0) {
                $progress_pct = 0.0;
            }
        }

        // elapsed_sec / rows_per_sec / eta_sec — started_at 가용 시에만
        $elapsed_sec  = null;
        $rows_per_sec = null;
        $eta_sec      = null;

        if ($started_at !== null && $started_at !== '') {
            $start_ts = strtotime($started_at);
            if ($start_ts !== false) {
                $elapsed_sec = max(0, time() - $start_ts);

                // rows_per_sec — elapsed > 0 이고 processed > 0 일 때만
                // (방금 시작해서 elapsed=0 또는 processed=0이면 의미 없는 값/Infinity 방지)
                if ($elapsed_sec > 0 && $processed > 0) {
                    $rows_per_sec = $processed / $elapsed_sec;

                    // eta_sec — total 알고 있고 아직 남은 rows가 있을 때
                    if ($total > 0 && $processed < $total && $rows_per_sec > 0.0) {
                        $remaining = $total - $processed;
                        $eta_sec   = (int)ceil($remaining / $rows_per_sec);
                    } elseif ($total > 0 && $processed >= $total) {
                        // 완료 직전/완료 — 남은 시간 0
                        $eta_sec = 0;
                    }
                    // total=0 이면 eta 산출 불가 → null 유지
                }
            }
        }

        return [
            'status'       => $status,
            'processed'    => $processed,
            'total'        => $total,
            'failed'       => $failed,
            'progress_pct' => $progress_pct,
            'rows_per_sec' => $rows_per_sec,
            'eta_sec'      => $eta_sec,
            'elapsed_sec'  => $elapsed_sec,
        ];
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
