<?php
// tests/Manual/test_marketo_retry.php
// 토큰 자동 갱신 + retry 동작을 실제 Marketo API에 대해 검증 (PHPUnit과 분리된 수동 라이브 테스트).
// 실행: /Applications/XAMPP/xamppfiles/bin/php tests/Manual/test_marketo_retry.php
declare(strict_types=1);

define('RUNNING_AS_CLI', true);
chdir(dirname(__DIR__, 2));

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Marketo/MarketoAPI.php';

// ── 헬퍼 ──────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function ok(string $name, string $detail = ''): void {
    global $pass; $pass++;
    echo "\033[32m✓\033[0m $name" . ($detail ? "  ($detail)" : '') . PHP_EOL;
}

function ng(string $name, string $detail): void {
    global $fail; $fail++;
    echo "\033[31m✗\033[0m $name  ($detail)" . PHP_EOL;
}

function section(string $title): void {
    echo PHP_EOL . "─── $title ───" . PHP_EOL;
}

/** private/protected 메서드 호출 헬퍼 */
function invoke_private(string $class, string $method, array $args = []): mixed {
    $r = new ReflectionMethod($class, $method);
    $r->setAccessible(true);
    return $r->invoke(null, ...$args);
}

// ── [1] 토큰 캐시 만료 시 자동 갱신 ───────────────────────────
section('[1] 토큰 캐시 만료 시 자동 갱신');

$cache_file = TOKEN_CACHE_FILE;

// 정상 토큰 1회 발급해서 캐시 파일 확보
try {
    $token1 = MarketoAPI::getAccessToken();
    if (!$token1) throw new RuntimeException('토큰 발급 실패');
    ok('초기 토큰 발급', 'token=' . substr($token1, 0, 12) . '...');
} catch (Throwable $e) {
    ng('초기 토큰 발급', $e->getMessage());
    echo "\n→ Marketo 자격증명을 확인하세요. 이후 테스트 중단.\n";
    exit(1);
}

// 캐시 파일을 expired 시각으로 조작
$old_cache = json_decode((string)file_get_contents($cache_file), true);
$old_token = $old_cache['token'] ?? '';
file_put_contents($cache_file, json_encode([
    'token'      => 'INVALIDATED_' . bin2hex(random_bytes(4)),
    'expires_at' => time() - 60, // 1분 전 만료
]));

// 다시 호출하면 새 토큰을 받아야 함
try {
    $token2 = MarketoAPI::getAccessToken();
    if ($token2 === '' || str_starts_with($token2, 'INVALIDATED_')) {
        ng('만료 캐시 → 재발급', '캐시된 만료 토큰이 그대로 반환됨');
    } else {
        ok('만료 캐시 → 재발급', 'new token=' . substr($token2, 0, 12) . '...');
    }
    // 캐시 파일이 새 만료시각(현재+잠시 후)으로 갱신됐는지 확인
    $new_cache = json_decode((string)file_get_contents($cache_file), true);
    if (($new_cache['expires_at'] ?? 0) > time() + 60) {
        ok('캐시 파일 갱신', 'expires_at=' . date('Y-m-d H:i:s', $new_cache['expires_at']));
    } else {
        ng('캐시 파일 갱신', 'expires_at가 갱신되지 않음');
    }
} catch (Throwable $e) {
    ng('만료 캐시 → 재발급', $e->getMessage());
}

// ── [2] 정상 호출 (회귀 검증) ─────────────────────────────────
section('[2] 정상 호출 (실제 Marketo API)');
try {
    $emails = MarketoAPI::getEmailList();
    if (is_array($emails)) {
        ok('getEmailList()', count($emails) . '개 이메일');
    } else {
        ng('getEmailList()', '배열이 아님');
    }
} catch (Throwable $e) {
    ng('getEmailList()', $e->getMessage());
}

// ── [3] 네트워크 오류 → 3회 재시도 후 fail ────────────────────
section('[3] 네트워크 오류 retry (약 14초 소요)');
$start = microtime(true);
try {
    invoke_private(MarketoAPI::class, 'curl', [
        'GET',
        'https://invalid.marketo-test.example.invalid/rest/v1/leads.json',
        ['Authorization: Bearer test'],
        null,
    ]);
    ng('네트워크 오류 retry', '예외가 발생하지 않음 (예상: RuntimeException)');
} catch (RuntimeException $e) {
    $elapsed = microtime(true) - $start;
    if ($elapsed >= 12 && $elapsed <= 20) {
        ok('네트워크 오류 retry', sprintf('약 %.1f초 소요 (2+4+8s 백오프)', $elapsed));
    } else {
        ng('네트워크 오류 retry', sprintf('예상 12~20초, 실제 %.1f초', $elapsed));
    }
} catch (Throwable $e) {
    ng('네트워크 오류 retry', '예상치 못한 예외: ' . $e->getMessage());
}

// ── [3.5] POST는 네트워크 오류에 재시도하지 않음 (부작용 중복 방지) ──
section('[3.5] POST 네트워크 오류 즉시 fail (재시도 없음)');
$start = microtime(true);
try {
    invoke_private(MarketoAPI::class, 'curl', [
        'POST',
        'https://invalid.marketo-test.example.invalid/rest/v1/leads.json',
        ['Authorization: Bearer test', 'Content-Type: application/json'],
        ['action' => 'createOrUpdate', 'input' => []],
    ]);
    ng('POST 즉시 fail', '예외가 발생하지 않음');
} catch (RuntimeException $e) {
    $elapsed = microtime(true) - $start;
    if ($elapsed < 2) {
        ok('POST 즉시 fail', sprintf('약 %.2f초 (재시도 없음 — 부작용 중복 방지)', $elapsed));
    } else {
        ng('POST 즉시 fail', sprintf('재시도 발생한 듯 — %.1f초 소요 (예상: <2s)', $elapsed));
    }
} catch (Throwable $e) {
    ng('POST 즉시 fail', '예상치 못한 예외: ' . $e->getMessage());
}

// ── [4] HTTP 5xx 감지 (httpbin.org) ───────────────────────────
section('[4] HTTP 5xx 감지 (옵션, httpbin.org 필요)');
try {
    $data = invoke_private(MarketoAPI::class, 'curlRaw', [
        'GET',
        'https://httpbin.org/status/503',
        [],
        null,
    ]);
    if (!empty($data['errors']) && (int)($data['errors'][0]['code'] ?? 0) === 503) {
        ok('curlRaw 5xx 감지', '503 응답이 errors[0].code에 표면화됨');
    } else {
        ng('curlRaw 5xx 감지', '5xx 응답이 errors 배열로 표면화되지 않음');
    }
} catch (Throwable $e) {
    echo "\033[33m⚠\033[0m HTTP 5xx 감지 — httpbin.org 접근 실패 (네트워크 환경 따라 정상): " . $e->getMessage() . PHP_EOL;
}

// ── 결과 ───────────────────────────────────────────────────────
echo PHP_EOL . str_repeat('=', 50) . PHP_EOL;
echo "결과: \033[32m{$pass} PASS\033[0m / \033[31m{$fail} FAIL\033[0m" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
