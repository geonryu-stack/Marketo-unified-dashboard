<?php
// src/Marketo/MarketoApiUsage.php
// PR-4 (δ) — Marketo API 일별·endpoint별 콜 카운터 도메인 모듈.
//
// 단일 책임: "오늘 어느 endpoint 가 몇 번 호출됐나" 한 가지.
// MarketoAPI::curl 진입에서 호출되어 marketo_api_calls 테이블에 upsert.
// 본업(API 호출) 영향 차단을 위해 record() 는 모든 예외를 swallow.
declare(strict_types=1);

class MarketoApiUsage
{
    /**
     * URL → endpoint 키 분류 (순수함수, 단위 테스트 용이).
     *
     * 정책:
     *   - 숫자 ID (folder/program/list/campaign id) 는 `:id` 로 치환
     *   - 카테고리 + 동사 형태로 압축 (lists.addLeads, campaigns.schedule, ...)
     *   - 인식되지 않은 URL 은 'other' 또는 path-based fallback
     *
     * 키는 VARCHAR(100) 제약 내, 영문 소문자·점·언더스코어만.
     */
    public static function classifyEndpoint(string $url, string $method = 'GET'): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') return 'other';
        // 숫자 ID 정규화
        $norm = preg_replace('#/\d+#', '/:id', $path);
        $norm = preg_replace('#\.json$#', '', $norm);
        $m = strtoupper($method);

        // OAuth 토큰 발급 (identity URL — 별도 한도)
        if (str_contains($norm, '/oauth/token')) return 'auth.token';

        // Bulk Import (별도 한도). batchId 는 hash/UUID 형태라 raw path 로 매칭.
        if (str_contains($path, '/bulk/v1/leads/batch/') && str_contains($path, '/status')) return 'bulk.status';
        if (str_contains($path, '/bulk/v1/leads'))                                          return 'bulk.import';

        // Activities
        if (str_contains($norm, '/v1/activities/pagingtoken')) return 'activities.pagingToken';
        if (str_contains($norm, '/v1/activities'))             return 'activities';

        // Static List 멤버 조작 — POST=add, DELETE(_method=DELETE 포함)=remove, GET=list
        if (preg_match('#/v1/lists/:id/leads#', $norm)) {
            // remove 는 ?_method=DELETE 쿼리로 전송됨
            if ($m === 'DELETE' || str_contains($url, '_method=DELETE')) return 'lists.removeLeads';
            if ($m === 'POST')                                            return 'lists.addLeads';
            return 'lists.listLeads';
        }

        // Lead upsert
        if (str_contains($norm, '/v1/leads')) return 'leads.upsert';

        // Smart Campaign 예약
        if (preg_match('#/v1/campaigns/:id/schedule#', $norm)) return 'campaigns.schedule';
        if (str_contains($norm, '/v1/campaigns'))              return 'campaigns.list';

        // Asset — Program / Email Program / Email / Folder
        if (preg_match('#/asset/v1/emailProgram/:id/schedule#', $norm))  return 'emailProgram.schedule';
        if (preg_match('#/asset/v1/emailProgram/:id/unapprove#', $norm)) return 'emailProgram.unapprove';
        if (preg_match('#/asset/v1/emailProgram/:id/schedule#', $norm))  return 'emailProgram.scheduleGet';
        if (preg_match('#/asset/v1/emailProgram/:id#', $norm))           return 'emailProgram.get';
        if (str_contains($norm, '/asset/v1/emailPrograms'))              return 'emailPrograms.list';

        if (preg_match('#/asset/v1/email/:id/sendSample#', $norm)) return 'email.sendSample';
        if (str_contains($norm, '/asset/v1/emails'))               return 'emails.list';

        if (preg_match('#/asset/v1/program/:id/tokens#', $norm)) return 'program.tokens';
        if (preg_match('#/asset/v1/program/:id#', $norm))        return 'program.get';
        if (str_contains($norm, '/asset/v1/programs'))           return 'programs.list';

        if (preg_match('#/asset/v1/folder/:id/tokens#', $norm))  return 'folder.tokens.set';
        if (preg_match('#/asset/v1/folder/:id/token#', $norm))   return 'folder.tokens.clear';

        if (str_contains($norm, '/asset/v1/staticLists')) return 'staticLists.list';

        return 'other';
    }

    /**
     * 1회 콜을 카운트한다. 본업 영향 차단을 위해 모든 예외 swallow.
     * MARKETO_API_USAGE_TRACKING=false 또는 DRY_RUN_MODE 면 noop.
     *
     * @param string $endpoint classifyEndpoint 결과
     * @param bool   $is_error true 면 error_count 도 +1
     */
    public static function record(string $endpoint, bool $is_error = false): void
    {
        if (!self::isEnabled()) return;

        try {
            $date = date('Y-m-d');
            $now  = now_str();
            $err_inc = $is_error ? 1 : 0;
            // ON DUPLICATE KEY UPDATE 로 race-safe 누적 (InnoDB 보장).
            DB::exec(
                'INSERT INTO marketo_api_calls (`date`, `endpoint`, `count`, `error_count`, `last_updated`)
                 VALUES (?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   `count`        = `count` + 1,
                   `error_count`  = `error_count` + VALUES(`error_count`),
                   `last_updated` = VALUES(`last_updated`)',
                [$date, $endpoint, $err_inc, $now]
            );
        } catch (Throwable $e) {
            // 본업(API 호출) 에 영향 주면 안 됨. 단지 로깅 인프라일 뿐.
            // 운영자 인지를 위해 stderr 만 (관찰자 부담 없이).
            if (defined('STDERR')) {
                @fwrite(STDERR, '[MarketoApiUsage::record] ' . $e->getMessage() . "\n");
            }
        }
    }

    /**
     * 특정 일자의 endpoint 별 분포 + 합계. 운영자 조회 API 가 사용.
     *
     * @return array{date:string, total:int, errors:int, by_endpoint:array<string,array{count:int,error_count:int}>}
     */
    public static function getDailySummary(?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        if (!self::isEnabled()) {
            return ['date' => $date, 'total' => 0, 'errors' => 0, 'by_endpoint' => []];
        }
        $rows = DB::all(
            'SELECT endpoint, count, error_count
               FROM marketo_api_calls
              WHERE `date` = ?
              ORDER BY count DESC',
            [$date]
        );
        $total = 0;
        $errors = 0;
        $by = [];
        foreach ($rows as $r) {
            $c = (int)$r['count'];
            $e = (int)$r['error_count'];
            $total  += $c;
            $errors += $e;
            $by[$r['endpoint']] = ['count' => $c, 'error_count' => $e];
        }
        return ['date' => $date, 'total' => $total, 'errors' => $errors, 'by_endpoint' => $by];
    }

    /** kill switch — config 상수 부재 또는 false, 또는 DRY_RUN 이면 비활성. */
    private static function isEnabled(): bool
    {
        if (defined('DRY_RUN_MODE') && DRY_RUN_MODE === true) return false;
        if (!defined('MARKETO_API_USAGE_TRACKING')) return false;
        return MARKETO_API_USAGE_TRACKING === true;
    }
}
