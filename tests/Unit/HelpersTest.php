<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sprint 0 INFRA — helpers.php 신규/확장 함수의 단위 테스트.
 *
 * job_log() 의 DB 부수효과는 단위 테스트 범위 밖이므로 여기서는
 *  - 시그니처(BC)를 ReflectionFunction 으로 검증하고,
 *  - 옵션 run_id 가 주어졌을 때 CLI stdout prefix가 붙는지만 확인한다.
 */
final class HelpersTest extends TestCase
{
    // ── is_dry_run ───────────────────────────────────────────────

    public function testIsDryRunDefaultsFalseWhenUndefined(): void
    {
        // DRY_RUN_MODE 상수가 정의되지 않은 환경에서는 false 여야 한다.
        if (!defined('DRY_RUN_MODE')) {
            $this->assertFalse(is_dry_run());
        } else {
            // 테스트 부트스트랩이 이미 정의해버린 경우 — 정의된 값과 일치해야 함.
            $this->assertSame((bool)DRY_RUN_MODE && DRY_RUN_MODE === true, is_dry_run());
        }
    }

    // ── job_log signature (BC 보장) ──────────────────────────────

    public function testJobLogSignatureIsBackwardCompatible(): void
    {
        $r = new ReflectionFunction('job_log');
        $params = $r->getParameters();
        // 기존 4개 인자 + 신규 run_id 1개 = 총 5개
        $this->assertSame(5, count($params), 'job_log은 5개 인자(BC + run_id) 여야 함');

        $this->assertSame('message',     $params[0]->getName());
        $this->assertFalse($params[0]->isOptional(), 'message는 필수');

        $this->assertSame('campaign_id', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional());
        $this->assertNull($params[1]->getDefaultValue());

        $this->assertSame('step',        $params[2]->getName());
        $this->assertSame('cron',        $params[2]->getDefaultValue());

        $this->assertSame('status',      $params[3]->getName());
        $this->assertSame('info',        $params[3]->getDefaultValue());

        // 신규 추가 인자
        $this->assertSame('run_id',      $params[4]->getName());
        $this->assertTrue($params[4]->isOptional(), 'run_id는 옵션이어야 BC 보장');
        $this->assertNull($params[4]->getDefaultValue());
        $this->assertTrue($params[4]->allowsNull(), 'run_id는 nullable');
    }

    public function testJobLogPrintsRunIdPrefixOnCli(): void
    {
        // RUNNING_AS_CLI 가 정의되지 않은 phpunit 환경에서는 stdout 출력이 일어나지 않으므로
        // 동적으로 정의해 한 줄만 캡처. campaign_id=null 이므로 DB::exec 는 호출되지 않음.
        if (!defined('RUNNING_AS_CLI')) {
            define('RUNNING_AS_CLI', true);
        }
        if (!RUNNING_AS_CLI) {
            $this->markTestSkipped('RUNNING_AS_CLI=false 환경');
        }

        ob_start();
        try {
            job_log('hello world', null, 'cron', 'info', 'abcdef0123456789-deadbeef-0000-0000-000000000000');
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        // 단축 prefix(앞 8자) 가 라인에 포함되어야 한다.
        $this->assertStringContainsString('[run:abcdef01]', $out);
        $this->assertStringContainsString('hello world', $out);
    }

    public function testJobLogWithoutRunIdHasNoPrefix(): void
    {
        if (!defined('RUNNING_AS_CLI')) {
            define('RUNNING_AS_CLI', true);
        }
        if (!RUNNING_AS_CLI) {
            $this->markTestSkipped('RUNNING_AS_CLI=false 환경');
        }

        ob_start();
        try {
            job_log('plain message', null, 'cron', 'info'); // run_id 미지정 = 기존 호출 방식
            $out = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        // run_id가 없으면 prefix가 없어야 BC 유지
        $this->assertStringNotContainsString('[run:', $out);
        $this->assertStringContainsString('plain message', $out);
    }

    // ── mask_email_pii (DB Sprint 0) ─────────────────────────────

    public function testMasksStandardEmail(): void
    {
        $this->assertSame(
            'ge***@af***.com',
            mask_email_pii('geonryu@afewgoodsoft.com')
        );
    }

    public function testMasksShortLocalAndShortDomain(): void
    {
        $this->assertSame('a***@b***.io', mask_email_pii('a@b.io'));
    }

    public function testReturnsTripleStarForNonEmail(): void
    {
        $this->assertSame('***', mask_email_pii('not-an-email'));
        $this->assertSame('***', mask_email_pii(''));
        $this->assertSame('***', mask_email_pii('   '));
        $this->assertSame('***', mask_email_pii('user@localhost'));
        $this->assertSame('***', mask_email_pii('@example.com'));
        $this->assertSame('***', mask_email_pii('user@'));
    }

    public function testMultiByteLocalIsNotCorrupted(): void
    {
        $masked = mask_email_pii('한글이메일@회사명.com');
        $this->assertSame('한글***@회사***.com', $masked);
    }

    public function testTldIsPreserved(): void
    {
        $this->assertSame(
            'us***@su***.kr',
            mask_email_pii('user@sub.example.kr')
        );
    }

    // ── Sprint 1 INFRA — record_status_transition 시그니처 ───────

    public function testRecordStatusTransitionSignatureFrozen(): void
    {
        // 안정 API: record_status_transition(campaign_id, from, to, actor='system',
        //                                    notes=null, run_id=null): void
        $this->assertTrue(
            function_exists('record_status_transition'),
            'record_status_transition 가 정의되어 있어야 함'
        );

        $r      = new ReflectionFunction('record_status_transition');
        $params = $r->getParameters();
        $this->assertSame(6, count($params), 'record_status_transition 은 6개 인자');

        $this->assertSame('campaign_id', $params[0]->getName());
        $this->assertFalse($params[0]->isOptional(), 'campaign_id 는 필수');

        $this->assertSame('from', $params[1]->getName());
        $this->assertFalse($params[1]->isOptional(), 'from 은 필수 (null 가능)');
        $this->assertTrue($params[1]->allowsNull(),  'from 은 nullable');

        $this->assertSame('to', $params[2]->getName());
        $this->assertFalse($params[2]->isOptional(), 'to 는 필수');

        $this->assertSame('actor',  $params[3]->getName());
        $this->assertTrue($params[3]->isOptional());
        $this->assertSame('system', $params[3]->getDefaultValue());

        $this->assertSame('notes', $params[4]->getName());
        $this->assertTrue($params[4]->isOptional());
        $this->assertNull($params[4]->getDefaultValue());
        $this->assertTrue($params[4]->allowsNull());

        $this->assertSame('run_id', $params[5]->getName());
        $this->assertTrue($params[5]->isOptional());
        $this->assertNull($params[5]->getDefaultValue());
        $this->assertTrue($params[5]->allowsNull());

        // 반환 타입 = void
        $returnType = $r->getReturnType();
        $this->assertNotNull($returnType, '반환 타입이 선언되어 있어야 함');
        $this->assertSame('void', (string)$returnType);
    }

    // ── Sprint 1 INFRA — screenshot_save 가드 ─────────────────────

    public function testScreenshotSaveThrowsOnMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/존재하지 않/u');

        screenshot_save('/nonexistent/path/to/file.png', 'camp-xyz', 'evidence.png');
    }

    public function testScreenshotSaveRejectsDisallowedExtension(): void
    {
        // 임시 파일을 만들되 확장자를 .gif (허용 외)로 지정해 화이트리스트 위반을 트리거.
        $tmp = tempnam(sys_get_temp_dir(), 'ss_test_');
        $this->assertNotFalse($tmp);
        // 최소 PNG 시그니처 8바이트를 써둬도 확장자 화이트리스트에서 컷되어야 함.
        file_put_contents($tmp, "\x89PNG\r\n\x1a\n");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/허용되지 않은 확장자/u');
            screenshot_save($tmp, 'camp-abc', 'attack.gif');
        } finally {
            @unlink($tmp);
        }
    }

    public function testScreenshotSaveRejectsOversizedFile(): void
    {
        // 5MB + 1 바이트 임시 파일 생성 후 거부 확인.
        $tmp = tempnam(sys_get_temp_dir(), 'ss_big_');
        $this->assertNotFalse($tmp);

        $fp = fopen($tmp, 'wb');
        $this->assertNotFalse($fp);
        // 5MB+1: 큰 청크로 빠르게.
        $chunk = str_repeat("\0", 1024 * 1024);
        for ($i = 0; $i < 5; $i++) {
            fwrite($fp, $chunk);
        }
        fwrite($fp, "\0"); // +1 byte → 5MB 초과
        fclose($fp);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/크기 초과/u');
            screenshot_save($tmp, 'camp-abc', 'big.png');
        } finally {
            @unlink($tmp);
        }
    }

    public function testScreenshotSaveSignatureFrozen(): void
    {
        $this->assertTrue(function_exists('screenshot_save'));

        $r      = new ReflectionFunction('screenshot_save');
        $params = $r->getParameters();
        $this->assertSame(3, count($params));

        $this->assertSame('tmp_path',      $params[0]->getName());
        $this->assertSame('campaign_id',   $params[1]->getName());
        $this->assertSame('original_name', $params[2]->getName());

        $returnType = $r->getReturnType();
        $this->assertNotNull($returnType, '반환 타입이 선언되어 있어야 함');
        $this->assertSame('string', (string)$returnType);
    }

    // ── check_lead_count_drift (Sprint 1 DB — C-LEAD-COUNT) ──────
    //
    // 본 헬퍼는 DB::one('SELECT last_count FROM segments WHERE id=?') 한 번만 호출한다.
    // PHP는 정적 메서드 모의가 까다로워, 테스트 부트스트랩에서 `DB` 클래스를
    // 가벼운 인메모리 스텁(FakeDB.php → 별도 alias)으로 갈음한다.
    // 본 테스트군은 그 스텁의 last_count 슬롯을 직접 세팅해 분기 검증.

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('DB', false)) {
            // 테스트 전용 페이크 DB — production DB.php와 동일 시그니처(static one/exec/all).
            eval('class DB {
                public static array $segments = [];
                public static function one(string $sql, array $params = []): ?array {
                    if (str_contains($sql, "FROM segments") && str_contains($sql, "last_count")) {
                        $id = $params[0] ?? "";
                        return self::$segments[$id] ?? null;
                    }
                    return null;
                }
                public static function exec(string $sql, array $params = []): int { return 0; }
                public static function all(string $sql, array $params = []): array { return []; }
            }');
        }
        DB::$segments = [];
    }

    public function testDriftReturnsNullWhenLastCountIsNull(): void
    {
        // 첫 회차: segments에 행이 아예 없거나 last_count=null → 비교 기준 없음 → null.
        DB::$segments['seg-A'] = ['last_count' => null];
        $this->assertNull(check_lead_count_drift('seg-A', 1500));

        DB::$segments = []; // 행 자체가 없는 케이스도 동일하게 null.
        $this->assertNull(check_lead_count_drift('seg-A', 1500));
    }

    public function testDriftReturnsNullWhenCountIsIdentical(): void
    {
        DB::$segments['seg-B'] = ['last_count' => 1000];
        $this->assertNull(check_lead_count_drift('seg-B', 1000));
    }

    public function testDriftReturnsNullWhenChangeBelowThreshold(): void
    {
        // 1000 → 1300 = +30% < 50% → 무경고
        DB::$segments['seg-C'] = ['last_count' => 1000];
        $this->assertNull(check_lead_count_drift('seg-C', 1300));
        // 1000 → 700 = -30% < 50% → 무경고
        $this->assertNull(check_lead_count_drift('seg-C', 700));
        // 경계값(정확히 50%) — threshold 초과가 아니므로 null
        $this->assertNull(check_lead_count_drift('seg-C', 1500));
        $this->assertNull(check_lead_count_drift('seg-C', 500));
    }

    public function testDriftWarnsOnLargeIncrease(): void
    {
        // 1500 → 12000 = +700% → 경고
        DB::$segments['seg-D'] = ['last_count' => 1500];
        $msg = check_lead_count_drift('seg-D', 12000);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('1,500', $msg);
        $this->assertStringContainsString('12,000', $msg);
        $this->assertStringContainsString('+700%', $msg);
        $this->assertStringContainsString('50%', $msg);
        $this->assertStringContainsString('드리프트', $msg);
    }

    public function testDriftWarnsOnLargeDecrease(): void
    {
        // 10000 → 1000 = -90% → 경고
        DB::$segments['seg-E'] = ['last_count' => 10000];
        $msg = check_lead_count_drift('seg-E', 1000);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('10,000', $msg);
        $this->assertStringContainsString('1,000', $msg);
        $this->assertStringContainsString('-90%', $msg);
        $this->assertStringContainsString('드리프트', $msg);
    }

    public function testDriftHonorsCustomThreshold(): void
    {
        // 1000 → 1200 = +20%. 기본 threshold(50%)에선 무경고지만 10% threshold로는 경고.
        DB::$segments['seg-F'] = ['last_count' => 1000];
        $this->assertNull(check_lead_count_drift('seg-F', 1200, 0.5));
        $msg = check_lead_count_drift('seg-F', 1200, 0.1);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('+20%', $msg);
        $this->assertStringContainsString('10%', $msg); // threshold 표시
    }

    // ── Sprint 3 DB (③) — build_where_clause v2 (OR/NOT 백워드 호환) ──

    /** 테스트용 작은 필드정의 — get_field_defs()의 일부와 호환 */
    private function tinyFieldDefs(): array
    {
        return [
            ['field' => 'country',          'label' => '국가',     'type' => 'select',
             'options' => ['KR','US','JP']],
            ['field' => 'days_since_login', 'label' => '로그인경과',  'type' => 'number',
             'sql_expr' => 'DATEDIFF(NOW(), last_login_at)'],
            ['field' => 'user_level',       'label' => '레벨',     'type' => 'number'],
            ['field' => 'is_active',        'label' => '활성',     'type' => 'boolean'],
            ['field' => 'marketing_consent','label' => '동의',     'type' => 'boolean'],
        ];
    }

    public function testBuildWhereClauseV1FlatStillWorks(): void
    {
        // v1 평면 입력은 기존과 동일 — AND 결합, 결과 시그니처 ['sql','params'] 불변.
        $defs   = $this->tinyFieldDefs();
        $result = build_where_clause(
            [
                ['field' => 'country',    'operator' => '=', 'value' => 'KR'],
                ['field' => 'user_level', 'operator' => '>=','value' => '3'],
            ],
            $defs
        );

        $this->assertArrayHasKey('sql',    $result);
        $this->assertArrayHasKey('params', $result);
        $this->assertSame('`country` = ? AND `user_level` >= ?', $result['sql']);
        $this->assertSame(['KR', 3], $result['params']);
    }

    public function testBuildWhereClauseEmptyInputReturnsOneEqualsOne(): void
    {
        $defs = $this->tinyFieldDefs();
        $this->assertSame(
            ['sql' => '1=1', 'params' => []],
            build_where_clause([], $defs)
        );
    }

    public function testBuildWhereClauseV2OrCombination(): void
    {
        // {op:'OR', children:[v1-leaf, v1-leaf]} → (a) OR (b)
        $defs = $this->tinyFieldDefs();
        $tree = [
            'op' => 'OR',
            'children' => [
                ['field' => 'country',    'operator' => '=',  'value' => 'KR'],
                ['field' => 'user_level', 'operator' => '>=', 'value' => '5'],
            ],
        ];
        $result = build_where_clause($tree, $defs);

        // 각 leaf 노드는 _build_where_node()에서 1요소 평면 배열로 위임되며,
        // _build_where_v1_flat() 결과를 다시 괄호로 감싸 OR 결합한다.
        $this->assertSame('(`country` = ?) OR (`user_level` >= ?)', $result['sql']);
        $this->assertSame(['KR', 5], $result['params']);
    }

    public function testBuildWhereClauseV2NestedAndOr(): void
    {
        // {op:'AND', children:[{op:'OR',children:[KR,JP]}, leaf user_level>=3]}
        // 운영자가 "한국 또는 일본 중에서 레벨 3 이상" 같은 모수를 만드는 가장 흔한 케이스.
        $defs = $this->tinyFieldDefs();
        $tree = [
            'op' => 'AND',
            'children' => [
                ['op' => 'OR', 'children' => [
                    ['field' => 'country', 'operator' => '=', 'value' => 'KR'],
                    ['field' => 'country', 'operator' => '=', 'value' => 'JP'],
                ]],
                ['field' => 'user_level', 'operator' => '>=', 'value' => '3'],
            ],
        ];
        $result = build_where_clause($tree, $defs);
        $this->assertSame(
            '((`country` = ?) OR (`country` = ?)) AND (`user_level` >= ?)',
            $result['sql']
        );
        $this->assertSame(['KR', 'JP', 3], $result['params']);
    }

    public function testBuildWhereClauseV2Not(): void
    {
        // {op:'NOT', child:v1-leaf}  → NOT (a)
        $defs = $this->tinyFieldDefs();
        $tree = [
            'op'    => 'NOT',
            'child' => ['field' => 'country', 'operator' => '=', 'value' => 'KR'],
        ];
        $result = build_where_clause($tree, $defs);
        $this->assertSame('NOT (`country` = ?)', $result['sql']);
        $this->assertSame(['KR'], $result['params']);
    }

    public function testBuildWhereClauseV2EmptyChildrenIsOneEqualsOne(): void
    {
        // 빈 children — 안전쪽 1=1 (v1 빈 입력 동작과 일치).
        $defs = $this->tinyFieldDefs();
        $result = build_where_clause(['op' => 'AND', 'children' => []], $defs);
        $this->assertSame(['sql' => '1=1', 'params' => []], $result);

        $result = build_where_clause(['op' => 'OR', 'children' => []], $defs);
        $this->assertSame(['sql' => '1=1', 'params' => []], $result);
    }

    public function testBuildWhereClauseV2NotNotedAsBlocking(): void
    {
        // NOT의 자식 결과가 '1=1'이면 SQL은 'NOT (1=1)' → 항상 거짓. 의도된 안전 동작.
        $defs = $this->tinyFieldDefs();
        $result = build_where_clause(
            ['op' => 'NOT', 'child' => ['op' => 'AND', 'children' => []]],
            $defs
        );
        $this->assertSame('NOT (1=1)', $result['sql']);
        $this->assertSame([], $result['params']);
    }

    public function testBuildWhereClauseV2UnknownOpThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/지원하지 않는 필터 op/u');
        build_where_clause(
            ['op' => 'XOR', 'children' => []],
            $this->tinyFieldDefs()
        );
    }

    // ── Sprint 3 INFRA — ensure_run_id ──────────────────────────

    public function testEnsureRunIdSignatureFrozen(): void
    {
        $this->assertTrue(function_exists('ensure_run_id'));
        $r      = new ReflectionFunction('ensure_run_id');
        $params = $r->getParameters();
        $this->assertSame(1, count($params));
        $this->assertSame('campaign', $params[0]->getName());
        $this->assertFalse($params[0]->isOptional());
        $this->assertSame('array', (string)$params[0]->getType());
        $this->assertSame('string', (string)$r->getReturnType());
    }

    public function testEnsureRunIdReturnsExistingValue(): void
    {
        $existing = 'abcdef01-2345-4678-89ab-cdef01234567';
        $got = ensure_run_id(['id' => 'camp-1', 'run_id' => $existing]);
        $this->assertSame($existing, $got);
    }

    public function testEnsureRunIdGeneratesWhenMissing(): void
    {
        $got = ensure_run_id(['id' => 'camp-2', 'run_id' => '']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $got
        );
        $got2 = ensure_run_id(['id' => 'camp-3']);
        $this->assertNotSame($got, $got2);
    }

    public function testEnsureRunIdThrowsWithoutCampaignId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/campaign\["id"\]/');
        ensure_run_id(['run_id' => null]);
    }
}
