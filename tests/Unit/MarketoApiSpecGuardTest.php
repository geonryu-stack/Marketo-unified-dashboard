<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2026-05-26 검수에서 잡힌 Marketo spec 위반 결함들의 회귀 가드.
 *
 * 단위 테스트로 메서드 동작을 mocking 검증하기 어려운 결함들 — 코드 *모양* 자체에 박힌
 * 안티패턴 (잘못된 URL prefix, 잘못된 HTTP method, 누락된 필수 파라미터) 을 정적 분석으로 차단.
 *
 * 핸드오프 직후 외부 개발자가 "단순 수정" 으로 안티패턴을 되돌리는 회귀를 막는 목적.
 *
 * 검수 출처:
 *  - marketo-sync-agent (Marketo 도메인 spec 대조)
 *  - superpowers:code-reviewer (SEV1 P0 후속 독립 리뷰)
 *  - Adobe Experience League 공식 spec 페이지
 */
final class MarketoApiSpecGuardTest extends TestCase
{
    private static string $api;
    private static string $bulk;

    public static function setUpBeforeClass(): void
    {
        self::$api  = (string)file_get_contents(__DIR__ . '/../../src/Marketo/MarketoAPI.php');
        self::$bulk = (string)file_get_contents(__DIR__ . '/../../src/Marketo/MarketoBulkImport.php');
    }

    /**
     * C1 회귀 가드 — MARKETO_REST_URL 은 이미 '...mktorest.com/rest' 로 끝나므로
     * `MARKETO_REST_URL . '/rest/'` 형태로 호출을 작성하면 `.../rest/rest/...` 이중 prefix → 영구 404.
     * 과거 getSmartCampaigns() 가 이 패턴이었음 (담당자 검수 C1, 2026-05-26 수정).
     */
    public function testNoDoubleRestPrefixInMarketoUrls(): void
    {
        $this->assertStringNotContainsString(
            "MARKETO_REST_URL . '/rest/",
            self::$api,
            'C1 회귀: MARKETO_REST_URL 뒤에 /rest/ 가 또 붙으면 영구 404. /v1/ 또는 /asset/v1/ 로 시작해야 함.'
        );
        // identity 도 비슷한 분리. MARKETO_IDENTITY_URL 은 '.../identity' 로 끝남.
        $this->assertStringNotContainsString(
            "MARKETO_IDENTITY_URL . '/identity/",
            self::$api,
            'C1 회귀: MARKETO_IDENTITY_URL 뒤에 /identity/ 가 또 붙으면 영구 404.'
        );
    }

    /**
     * H1 회귀 가드 — removeLeadsFromList 는 Marketo 표준 패턴 (POST + ?_method=DELETE + body) 을 따라야 함.
     * HTTP DELETE + body 는 WAF/proxy 에서 body 가 strip 되어 silent 200 (실제 제거 안 됨) 위험.
     * 60K 발송의 list-refresh 가 silent fail 하면 이전 leads 가 새 leads 와 같이 발송 → SEV1 급 사고.
     */
    public function testRemoveLeadsFromListUsesPostMethodOverride(): void
    {
        // 메서드 본문 범위만 추출 — 다른 메서드의 self::curl 호출과 섞이지 않도록.
        $pattern = '/public static function removeLeadsFromList\([^)]*\)[^{]*\{.*?\n    \}/s';
        $this->assertSame(
            1,
            preg_match($pattern, self::$api, $m),
            'removeLeadsFromList 메서드 본문을 찾지 못함'
        );
        $body = $m[0];

        $this->assertStringContainsString(
            "self::curl('POST'",
            $body,
            'H1 회귀: removeLeadsFromList 는 POST 메서드를 사용해야 함 (DELETE + body 는 WAF strip 위험)'
        );
        $this->assertStringNotContainsString(
            "self::curl('DELETE'",
            $body,
            'H1 회귀: removeLeadsFromList 가 DELETE 메서드로 회귀 — body 가 strip 되어 silent 200 위험'
        );
        $this->assertStringContainsString(
            '_method=DELETE',
            $body,
            'H1: ?_method=DELETE query 가 빠지면 Marketo 가 멤버 *추가* 로 해석할 수 있음'
        );
    }

    /**
     * Bulk Import status endpoint 회귀 가드 — 공식 spec 은 GET /bulk/v1/leads/batch/{id}.json.
     * `/status.json` suffix 변종은 일부 인스턴스에서 404 → 폴링 cron 영구 stuck.
     *
     * 토큰 기반 검사 — 문자열 리터럴만 보고 주석은 무시 (사고 사후 documentation 가 매칭되는 것 차단).
     */
    public function testBulkImportStatusUrlMatchesSpec(): void
    {
        $literals = self::extractStringLiterals(self::$bulk);
        $bulk_paths = array_filter($literals, fn($s) => str_contains($s, '/bulk/v1/leads/batch/'));
        $this->assertNotEmpty($bulk_paths, 'Bulk Import status endpoint 호출이 사라짐');

        foreach ($bulk_paths as $p) {
            $this->assertStringNotContainsString(
                'status',
                $p,
                "Bulk Import status URL 회귀: 공식 path 는 /bulk/v1/leads/batch/{id}.json. " .
                "리터럴 '{$p}' 에 'status' 가 포함됨 → spec 위반"
            );
        }
    }

    /**
     * Codex stop-time review — Activity 폴링이 7d 윈도우 전에 일찍 종료되지 않아야 함.
     * 1h stale threshold 회귀 시 발송 직후 sent flood 후 트리클 시작 전 1h gap 으로
     * false-positive 종료 → open/click 통계 누락.
     */
    public function testActivityPollingProtects7DayWindow(): void
    {
        $cron = (string)file_get_contents(__DIR__ . '/../../cron/check_sent_activities.php');

        // 168h 강제 종료 가드 존재
        $this->assertMatchesRegularExpression(
            '/elapsed_min\s*>=\s*168\s*\*\s*60/',
            $cron,
            '7d (168h) 강제 종료 가드가 사라짐'
        );

        // min_elapsed_min 가드 (48h) — 발송 직후 false-positive 종료 차단
        $this->assertMatchesRegularExpression(
            '/min_elapsed_min\s*=\s*48\s*\*\s*60/',
            $cron,
            '발송 직후 48h 보호 가드가 제거됨 — open/click 트리클 시작 전 종료 위험'
        );

        // stale threshold 가 1h 같은 *짧은* 값으로 회귀하면 안 됨
        $this->assertDoesNotMatchRegularExpression(
            '/stale_threshold_s\s*=\s*3600\s*;/',
            $cron,
            'stale threshold 1h 회귀 — open/click 트리클 자연 silence 와 구분 못 함 → 일찍 종료'
        );
    }

    /**
     * Codex stop-time review — cancel acknowledgement gate 가 UI 에서 도달 가능해야 함.
     * 서버 단독으로 409 + requires_acknowledgement 만 돌려주면 운영자가 영원히 cancel 못 함.
     */
    public function testCancelUiHandlesAcknowledgementGate(): void
    {
        $js = (string)file_get_contents(__DIR__ . '/../../assets/js/campaign-actions.js');
        $this->assertStringContainsString(
            'acknowledge_sent',
            $js,
            'UI cancel 흐름이 acknowledge_sent body 를 보내지 않으면 H-3 게이트가 unreachable.'
        );
        $this->assertStringContainsString(
            'requires_acknowledgement',
            $js,
            'UI 가 서버 409 응답의 requires_acknowledgement 분기를 처리하지 않음.'
        );
        $this->assertStringContainsString(
            'already_sent_count',
            $js,
            'UI 가 already_sent_count 를 운영자에게 표시하지 않으면 cancel 결정 정보 부족.'
        );
    }

    /**
     * H3 — 백오프 길이 회귀 가드. RETRY_DELAYS 합계가 Marketo rate-limit 윈도우(20s) 보다 짧으면
     * burst 시 재시도 모두 같은 윈도우에서 소진 → throw → needs_manual_review 격리.
     */
    public function testRetryDelaysExceedRateLimitWindow(): void
    {
        require_once __DIR__ . '/../../src/Marketo/MarketoAPI.php';
        $sum = array_sum(MarketoAPI::RETRY_DELAYS);
        $this->assertGreaterThanOrEqual(
            20,
            $sum,
            "H3 회귀: RETRY_DELAYS 합계({$sum}s) 가 Marketo rate-limit 윈도우(20s) 보다 짧음. burst 시 격리 위험."
        );
    }

    /** H3 — decideBackoffSeconds 가 Retry-After 헤더를 우선하되 최소 기본 백오프 보장. */
    public function testDecideBackoffPrefersRetryAfterButFloorsDefault(): void
    {
        require_once __DIR__ . '/../../src/Marketo/MarketoAPI.php';
        // 기본 백오프보다 긴 Retry-After 는 그대로 사용
        $this->assertSame(60, MarketoAPI::decideBackoffSeconds(0, 60));
        // 기본 백오프보다 짧은 Retry-After 는 기본값으로 끌어올림 (Marketo 가 0/1초 줘도 안전)
        $default0 = MarketoAPI::RETRY_DELAYS[0];
        $this->assertSame($default0, MarketoAPI::decideBackoffSeconds(0, 1));
        // Retry-After 없으면 기본 백오프
        $this->assertSame($default0, MarketoAPI::decideBackoffSeconds(0, null));
        // attempt 가 RETRY_DELAYS 길이를 초과해도 마지막 값 fallback (out-of-bounds 안전)
        $delays = MarketoAPI::RETRY_DELAYS;
        $last   = $delays[count($delays) - 1];
        $this->assertSame($last, MarketoAPI::decideBackoffSeconds(99, null));
    }

    /**
     * 파일 소스에서 PHP 문자열 리터럴만 추출 (주석 / 코드 구조 제외).
     * 회귀 가드가 documentation 주석에 우연히 매칭되는 false positive 차단.
     */
    private static function extractStringLiterals(string $source): array
    {
        $tokens = token_get_all($source);
        $out = [];
        foreach ($tokens as $t) {
            if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                // T_CONSTANT_ENCAPSED_STRING 은 따옴표 포함 — 안쪽만 사용
                $out[] = substr($t[1], 1, -1);
            }
        }
        return $out;
    }

    /**
     * Folder Tokens API 필수 파라미터 회귀 가드 — name, type, value, folderType *모두* 필수.
     * folderType 누락 시 silent fail 가능 (운영자 인스턴스 차이) → My Token 동기화 무작동 →
     * 발송 이메일이 {{my.Title}} 치환 없이 그대로 발송. SEV1 변종.
     */
    public function testFolderTokensSpecRequiredParamsPresent(): void
    {
        // syncFolderMyTokens 메서드 본문 추출
        $pattern = '/public static function syncFolderMyTokens\([^)]*\)[^{]*\{.*?\n    \}/s';
        $this->assertSame(
            1,
            preg_match($pattern, self::$api, $m),
            'syncFolderMyTokens 메서드 본문을 찾지 못함'
        );
        $body = $m[0];

        $this->assertStringContainsString(
            "'folderType'",
            $body,
            "Folder Tokens API 회귀: folderType 는 Marketo spec 의 4 required 중 하나. 누락 시 silent fail."
        );
    }

    /**
     * Smart Campaign tokens 형식 회귀 가드 — Marketo SC schedule API 는 토큰 이름을
     * `{{my.X}}` braced 형식으로 받음. 평문 'X' 는 무시되거나 매칭 실패.
     */
    public function testSmartCampaignTokenNamesAreBraced(): void
    {
        $pattern = '/public static function scheduleSmartCampaign\([^)]*\)[^{]*\{.*?\n    \}/s';
        $this->assertSame(
            1,
            preg_match($pattern, self::$api, $m),
            'scheduleSmartCampaign 메서드 본문을 찾지 못함'
        );
        $body = $m[0];

        $this->assertStringContainsString(
            "'{{my.'",
            $body,
            'Smart Campaign 토큰 이름은 {{my.X}} braced 형식이어야 Marketo 가 매칭함'
        );
    }
}
