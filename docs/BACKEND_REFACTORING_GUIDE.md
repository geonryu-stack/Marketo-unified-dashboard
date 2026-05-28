# PHP 백엔드 리팩토링 가이드

> **대상 프로젝트**: marketo-send-automation  
> **스택**: PHP 8.x + MySQL (XAMPP) + Apache, Bootstrap 5 + Vanilla JS  
> **작성일**: 2026-05-28  
> **테스트 현황**: PHPUnit 229개 전체 통과  
> **범위**: PHP 백엔드만 (프론트엔드 JS 리팩토링은 별도 진행)

---

## 우선순위 및 예상 공수

| 순번 | 작업 | 난이도 | 예상 공수 | 위험도 |
|------|------|--------|-----------|--------|
| 1 | `helpers.php` 분리 (863줄 → 10개 서브파일) | 중 | 2~3시간 | 낮음 (전역 함수 유지, 호출측 변경 없음) |
| 2 | `sanitize_cap_int()` 중복 제거 | 하 | 15분 | 낮음 |
| 3 | API 보일러플레이트 추출 | 중 | 3~4시간 | 중간 (모든 API 파일 수정) |
| 4 | `campaigns.php` 핸들러 함수 추출 (751줄) | 중 | 1~2시간 | 낮음 (같은 파일 내 이동) |
| 5 | 테스트 영향도 분석 및 수정 | 하 | 30분 | 낮음 |

**권장 순서**: 1 → 2 → 5(테스트 확인) → 4 → 3

---

## 1. helpers.php 분리 (863줄 → 역할별 파일)

### 현재 상태

`src/helpers.php` (863줄)에 30개 이상의 전역 함수가 역할 구분 없이 나열되어 있다.
- HTTP 응답, UUID, 보안, SQL 필터, 캠페인 토큰, 시간 파싱, KPI, 로깅, 상태 레이블, 코호트 통계, 스크린샷 저장 등이 한 파일에 혼재.
- 주석으로 `// ── 섹션 ──` 형태의 구분은 있으나, 파일 단위 응집도가 없음.

### 변경 사항

`src/helpers/` 디렉터리를 신규 생성하고, 역할별 서브파일로 함수를 이동한다.
`src/helpers.php`는 모든 서브파일의 `require_once` 허브로만 남긴다.

**핵심 규칙: 전역 함수 시그니처를 100% 유지한다. 호출측 코드는 단 한 줄도 변경하지 않는다.**

#### 서브파일별 함수 매핑

##### (a) `src/helpers/http.php`

| 함수명 | 현재 위치 (줄 번호) |
|--------|---------------------|
| `json_ok(mixed $data = null): void` | 7~12 |
| `json_err(string $error, int $status = 400): void` | 14~20 |
| `parse_json_body(): array` | 505~522 |

##### (b) `src/helpers/uuid.php`

| 함수명 | 현재 위치 (줄 번호) |
|--------|---------------------|
| `new_uuid(): string` | 24~34 |
| `now_str(): string` | 36~39 |

##### (c) `src/helpers/security.php`

| 함수명 | 현재 위치 (줄 번호) |
|--------|---------------------|
| `assert_readonly(string $sql): void` | 43~49 |
| `mask_email_pii(string $email): string` | 57~81 |

##### (d) `src/helpers/filters.php`

| 함수명 | 현재 위치 (줄 번호) |
|--------|---------------------|
| `get_field_defs(): array` | 135~158 |
| `build_where_clause(array $filters, array $field_defs): array` | 177~193 |
| `_build_where_v1_flat(array $filters, array $def_map): array` | 199~251 |
| `_build_where_node(array $node, array $def_map): array` | 264~321 |
| `cast_filter_value(string $value, string $type): mixed` | 323~328 |
| `check_lead_count_drift(string $segment_id, int $current_count, float $threshold = 0.5): ?string` | 103~131 |

##### (e) `src/helpers/tokens.php`

| 함수명 | 현재 위치 (줄 번호) |
|--------|---------------------|
| `build_campaign_tokens(array $c): array` | 368~379 |
| `normalize_token_name(string $name): string` | 386~389 |
| `diff_campaign_tokens(array $expected, array $actual): array` | 399~426 |
| `mime_header_value(string $value): string` | 433~438 |
| `html_body_value(string $value): string` | 445~456 |

##### (f) `src/helpers/schedule.php`

| 함수명 | 현재 위치 (줄 번호) |
|--------|---------------------|
| `parse_send_time(string $raw): int` | 460~464 |
| `format_send_time_for_marketo(string $raw): string` | 482~496 |

##### (g) `src/helpers/kpi.php`

| 함수명 | 현재 위치 (줄 번호) |
|--------|---------------------|
| `_kpi_safe_select(string $sql, array $params = []): mixed` | 528~537 |
| `kpi_sent_this_week(): array` | 539~552 |
| `kpi_avg_approval_minutes(): array` | 554~571 |
| `kpi_avg_coverage_pct(): array` | 573~593 |
| `kpi_needs_manual_review_count(): array` | 595~608 |
| `kpi_trend_arrow_svg(?float $current, ?float $prev, bool $lower_is_better = false): string` | 611~628 |

##### (h) `src/helpers/logging.php`

| 함수명 / 상수 | 현재 위치 (줄 번호) |
|---------------|---------------------|
| `job_log(string $message, ?string $campaign_id = null, string $step = 'cron', string $status = 'info', ?string $run_id = null): void` | 641~658 |
| `is_dry_run(): bool` | 667~670 |
| `ensure_run_id(array $campaign): string` | 679~692 |
| `SCREENSHOT_MAX_BYTES` (상수) | 701 |
| `SCREENSHOT_ALLOWED_EXT` (상수) | 702 |
| `SCREENSHOT_ALLOWED_MIME` (상수) | 703 |
| `SCREENSHOT_STORAGE_SUBDIR` (상수) | 704 |
| `screenshot_save(string $tmp_path, string $campaign_id, string $original_name): string` | 715~794 |
| `record_status_transition(string $campaign_id, ?string $from, string $to, string $actor = 'system', ?string $notes = null, ?string $run_id = null): void` | 849~862 |

##### (i) `src/helpers/status.php`

| 함수명 | 현재 위치 (줄 번호) |
|--------|---------------------|
| `status_label(string $status): string` | 333~348 |
| `status_badge_class(string $status): string` | 350~364 |

##### (j) `src/helpers/cohort.php`

| 함수명 | 현재 위치 (줄 번호) |
|--------|---------------------|
| `compute_cohort_stats(array $row): array` | 814~826 |

### 변경 후 예시

**`src/helpers.php` (변경 후 — 함수 정의 없음, require_once 허브만)**

```php
<?php
// src/helpers.php — 전역 함수 허브.
// 모든 서브파일을 require_once 하여 기존 호출측 코드 변경 없이 동작.
declare(strict_types=1);

require_once __DIR__ . '/helpers/http.php';
require_once __DIR__ . '/helpers/uuid.php';
require_once __DIR__ . '/helpers/security.php';
require_once __DIR__ . '/helpers/filters.php';
require_once __DIR__ . '/helpers/tokens.php';
require_once __DIR__ . '/helpers/schedule.php';
require_once __DIR__ . '/helpers/kpi.php';
require_once __DIR__ . '/helpers/logging.php';
require_once __DIR__ . '/helpers/status.php';
require_once __DIR__ . '/helpers/cohort.php';
require_once __DIR__ . '/helpers/validation.php'; // sanitize_cap_int (작업 #2)
```

**`src/helpers/http.php` (예시)**

```php
<?php
// src/helpers/http.php — HTTP 응답 헬퍼
declare(strict_types=1);

function json_ok(mixed $data = null): void
{
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $error, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

function parse_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_err('Invalid JSON body', 400);
    }
    if (!is_array($data)) {
        json_err('JSON body must be an object', 400);
    }
    return $data;
}
```

### 주의 사항

1. **`declare(strict_types=1)`**: 현재 `helpers.php` 최상단에 선언되어 있다. 각 서브파일에도 반드시 `declare(strict_types=1)`을 추가해야 한다. PHP의 strict_types는 호출하는 파일(calling file) 기준이므로 서브파일 자체에 선언이 없으면 해당 파일 내에서 타입 강제가 풀린다.
2. **상수(const) 이동**: `SCREENSHOT_*` 상수 4개는 `logging.php`로 함께 이동한다. PHP 전역 상수이므로 어디서 정의해도 동일하게 접근 가능하지만, `screenshot_save()` 함수와 같은 파일에 두는 것이 응집도에 맞다.
3. **`_build_where_v1_flat()`과 `_build_where_node()`**: 언더스코어 접두사로 `@internal` 표기된 함수다. 외부에서 직접 호출하는 곳은 없으나, 전역 함수이므로 이동 후에도 동일하게 접근 가능하다. 그대로 `filters.php`에 포함.
4. **순환 의존 없음**: 서브파일 간 함수 호출이 있는지 확인해야 한다. 현재 유일한 교차 의존은:
   - `logging.php`의 `job_log()` → `uuid.php`의 `new_uuid()`, `now_str()` 호출
   - `logging.php`의 `record_status_transition()` → `uuid.php`의 `new_uuid()`, `now_str()` 호출
   - `logging.php`의 `ensure_run_id()` → `uuid.php`의 `new_uuid()` 호출
   - `filters.php`의 `check_lead_count_drift()` → DB 클래스(외부) 호출
   
   **해결**: `helpers.php`가 모든 서브파일을 순서대로 require_once하므로, `uuid.php`를 `logging.php`보다 먼저 require하면 문제없다 (위 예시의 순서가 이미 올바름).

---

## 2. sanitize_cap_int() 중복 제거

### 현재 상태

`sanitize_cap_int()` 함수가 두 파일에 중복 정의되어 있다:

**`api/segments.php` 17~28줄:**
```php
function sanitize_cap_int(mixed $raw, int $default): int
{
    if ($raw === null || $raw === '') return $default;
    if (!is_numeric($raw)) {
        json_err('cap 값은 0 이상 9999 이하의 정수여야 합니다.', 400);
    }
    $n = (int)$raw;
    if ($n < 0 || $n > 9999) {
        json_err('cap 값은 0 이상 9999 이하의 정수여야 합니다.', 400);
    }
    return $n;
}
```

**`api/rules.php` 7~20줄** (function_exists 가드로 감싸져 있음):
```php
if (!function_exists('sanitize_cap_int')) {
    function sanitize_cap_int(mixed $raw, int $default): int
    {
        // ... 동일 로직
    }
}
```

`api/rules.php`의 `function_exists` 가드는 `segments.php`와 같은 프로세스에서 로드될 경우를 방어한 것이지만, 근본적으로 중복 코드를 제거해야 한다.

### 변경 사항

1. **신규 파일 생성**: `src/helpers/validation.php`

```php
<?php
// src/helpers/validation.php — 입력값 검증 헬퍼
declare(strict_types=1);

/**
 * 리드별 cap 입력값을 0~9999 정수로 정규화. 음수/NaN 차단.
 * 누락(키 자체 없음) 시 $default 반환.
 */
function sanitize_cap_int(mixed $raw, int $default): int
{
    if ($raw === null || $raw === '') return $default;
    if (!is_numeric($raw)) {
        json_err('cap 값은 0 이상 9999 이하의 정수여야 합니다.', 400);
    }
    $n = (int)$raw;
    if ($n < 0 || $n > 9999) {
        json_err('cap 값은 0 이상 9999 이하의 정수여야 합니다.', 400);
    }
    return $n;
}
```

2. **`src/helpers.php`에 require_once 추가** (작업 #1의 허브에 이미 포함):
```php
require_once __DIR__ . '/helpers/validation.php';
```

3. **`api/segments.php`에서 삭제**: 17~28줄의 `sanitize_cap_int()` 함수 정의 전체를 삭제.

4. **`api/rules.php`에서 삭제**: 6~20줄의 `function_exists` 블록 전체를 삭제.

### 변경 후 예시

**`api/segments.php`** — 함수 정의가 사라지고, `index.php`가 이미 `helpers.php`를 require하므로 추가 require 불필요:

```php
<?php
// api/segments.php
declare(strict_types=1);
require_once __DIR__ . '/../src/Suppression.php';

$method = $_SERVER['REQUEST_METHOD'];
$params = $GLOBALS['route_params'] ?? [];
// ... (sanitize_cap_int 함수 정의 제거, 호출 코드는 그대로)
```

**`api/rules.php`** — 동일하게 function_exists 블록 제거:

```php
<?php
// api/rules.php — 발송 Rule 일괄 저장 (PUT only)
declare(strict_types=1);
require_once __DIR__ . '/../src/Suppression.php';

$method = $_SERVER['REQUEST_METHOD'];
// ... (sanitize_cap_int 호출은 그대로 유지)
```

### 주의 사항

1. **`api/content-presets.php`가 `require_once helpers.php`를 직접 한다** (17줄). 이 파일은 `index.php` 라우터를 통하지 않고 직접 require하는 경로가 있을 수 있으므로, `validation.php`가 `helpers.php` 허브에 포함되어 있으면 자동으로 로드된다. 별도 확인 필요 없음.
2. **`api/rules.php`는 `index.php`의 라우터를 통해 호출**된다 (`index.php` 30줄 부근 참조). `index.php`가 `helpers.php`를 require하므로 `sanitize_cap_int()`는 이미 로드된 상태.

---

## 3. API 보일러플레이트 추출

### 현재 상태

모든 API 파일(`api/campaigns.php`, `api/segments.php`, `api/dashboard.php`, `api/content-presets.php`, `api/rules.php`, `api/marketo.php`, `api/internal-db.php` 등 14개)에 동일한 보일러플레이트가 반복된다:

```php
$method = $_SERVER['REQUEST_METHOD'];
$params = $GLOBALS['route_params'] ?? [];
$id     = $params['id'] ?? null;
$action = $params['action'] ?? null;

try {
    if ($method === 'GET' && !$id) { ... }
    elseif ($method === 'GET' && $id) { ... }
    elseif ($method === 'POST' && !$id) { ... }
    // ...
    else { json_err('Not Found', 404); }
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}
```

**반복 패턴 분석** (3개 API 파일 비교):

| 파일 | method 추출 | params 추출 | try/catch 래핑 | 404 폴백 |
|------|-------------|-------------|----------------|----------|
| `api/campaigns.php:11~14` | O | O (`id`, `action`) | O (695~697) | O (691~693) |
| `api/segments.php:6~11` | O | O (`id`, `query_action`) | O (217~219) | O (214~216) |
| `api/dashboard.php:9~11` | O | O (`action`, `id_param`) | O (136~145) | O (133~135) |
| `api/content-presets.php:21~23` | O | O (`id`) | O (79~84) | O (76~78) |
| `api/rules.php:22` | O | - (PUT only) | O (77~82) | - |

### 변경 사항

`src/ApiBase.php`에 경량 함수를 만들어 보일러플레이트를 추출한다.

**클래스가 아닌 함수 기반으로 한다** — 기존 코드가 전역 함수 패턴이므로 클래스 도입은 과도하다.

```php
<?php
// src/ApiBase.php — API 보일러플레이트 추출
declare(strict_types=1);

/**
 * API 엔드포인트의 공통 진입점.
 *
 * @param callable $handler function(string $method, ?string $id, ?string $action, array $params): void
 *                          handler 내에서 json_ok() 또는 json_err()로 응답을 반환한다.
 * @param array    $options {
 *     'allowed_methods' => ['GET','POST','PUT','DELETE'],  // 허용 메서드. 기본 전체.
 *     'error_handler'   => callable(Throwable $e): void,   // 커스텀 에러 핸들러.
 * }
 */
function api_handle(callable $handler, array $options = []): void
{
    $method = $_SERVER['REQUEST_METHOD'];
    $params = $GLOBALS['route_params'] ?? [];
    $id     = $params['id'] ?? null;
    $action = $params['action'] ?? null;

    // 허용 메서드 체크
    $allowed = $options['allowed_methods'] ?? null;
    if ($allowed !== null && !in_array($method, $allowed, true)) {
        json_err('Method Not Allowed', 405);
    }

    try {
        $handler($method, $id, $action, $params);
    } catch (Throwable $e) {
        if (isset($options['error_handler']) && is_callable($options['error_handler'])) {
            ($options['error_handler'])($e);
        } else {
            json_err($e->getMessage(), 500);
        }
    }
}
```

### 변경 후 예시

**Before — `api/segments.php` (현재)**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Suppression.php';

$method = $_SERVER['REQUEST_METHOD'];
$params = $GLOBALS['route_params'] ?? [];
$id     = $params['id'] ?? null;
$query_action = $_GET['action'] ?? null;

try {
    if ($method === 'GET' && !$id) {
        $rows = DB::all('SELECT * FROM segments ORDER BY created_at DESC');
        json_ok($rows);
    }
    // ... 100+ 줄의 핸들러 로직 ...
    else {
        json_err('Not Found', 404);
    }
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}
```

**After — `api/segments.php` (리팩토링 후)**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Suppression.php';

api_handle(function (string $method, ?string $id, ?string $action, array $params): void {
    $query_action = $_GET['action'] ?? null;

    if ($method === 'GET' && !$id) {
        $rows = DB::all('SELECT * FROM segments ORDER BY created_at DESC');
        json_ok($rows);
    }
    // ... 핸들러 로직 그대로 유지 ...
    else {
        json_err('Not Found', 404);
    }
});
```

**Before — `api/dashboard.php` (현재, 커스텀 에러 핸들러 사용)**

```php
$method = $_SERVER['REQUEST_METHOD'];
$action = $GLOBALS['route_params']['action'] ?? '';
// ...
if ($method !== 'GET') {
    json_err('Method Not Allowed', 405);
}
try {
    // ...
} catch (Throwable $e) {
    // 내부 에러 메시지 로깅 후 일반화된 메시지 반환
    json_err('대시보드 데이터 조회 중 오류가 발생했습니다.', 500);
}
```

**After — `api/dashboard.php`**

```php
api_handle(
    function (string $method, ?string $id, ?string $action, array $params): void {
        // ... 핸들러 로직 그대로 ...
    },
    [
        'allowed_methods' => ['GET'],
        'error_handler' => function (Throwable $e): void {
            if (defined('STDERR')) {
                @fwrite(STDERR, '[api/dashboard] ' . $e->getMessage() . "\n");
            }
            error_log('[api/dashboard] ' . $e->getMessage());
            json_err('대시보드 데이터 조회 중 오류가 발생했습니다.', 500);
        },
    ]
);
```

### 적용 대상 API 파일 목록

| 파일 | 변환 난이도 | 비고 |
|------|-------------|------|
| `api/segments.php` | 낮음 | 표준 패턴 |
| `api/campaigns.php` | 중간 | 가장 큰 파일, 많은 action 분기 |
| `api/dashboard.php` | 낮음 | 커스텀 error_handler 사용 |
| `api/content-presets.php` | 낮음 | 표준 패턴 |
| `api/rules.php` | 낮음 | PUT only, allowed_methods 활용 |
| `api/marketo.php` | 낮음 | resource 기반 분기 (action 대신 resource 사용) |
| `api/internal-db.php` | 낮음 | action 기반 분기 |
| `api/health.php` | 낮음 | GET only |
| `api/schedules.php` | 낮음 | 표준 패턴 |
| `api/calendar.php` | 낮음 | 표준 패턴 |
| `api/groups.php` | 낮음 | 표준 패턴 |
| `api/segment-latest-tokens.php` | 낮음 | 표준 패턴 |
| `api/marketo-url-parse.php` | 낮음 | 표준 패턴 |
| `api/marketo-usage.php` | 낮음 | 표준 패턴 |

### 주의 사항

1. **`api_handle()`은 `index.php`에서 로드해야 한다**: `index.php`가 `src/ApiBase.php`를 require_once하거나, `helpers.php` 허브에 포함시킨다. 권장: `index.php`에 직접 `require_once __DIR__ . '/src/ApiBase.php'` 추가 (9줄 다음).
2. **`$GLOBALS['route_params']` 파라미터 이름이 API마다 다를 수 있다**: `api/marketo.php`는 `resource`, `api/dashboard.php`는 `action`과 `id_param`을 사용한다. `api_handle()`의 `$params` 배열을 통째로 전달하므로 handler 내에서 `$params['resource']` 등 자유롭게 접근 가능.
3. **`api/content-presets.php`는 `require_once helpers.php`를 직접 한다** (17줄). `api_handle()`이 `helpers.php`에 포함되지 않고 `ApiBase.php`로 분리되면, `content-presets.php`에도 `require_once ApiBase.php`를 추가하거나, `index.php` 라우터 경로를 통해서만 호출되도록 정리해야 한다.
4. **점진적 적용**: 14개 파일을 한 번에 바꾸지 말고, `segments.php` 하나를 먼저 변환하고 테스트 통과를 확인한 후 나머지를 적용한다.

---

## 4. campaigns.php 핸들러 함수 추출 (751줄)

### 현재 상태

`api/campaigns.php` (751줄)의 메인 dispatch 블록(`try` 내부, 16~693줄)에 모든 액션 핸들러가 인라인으로 작성되어 있다. 특히 다음 세 블록이 100줄 이상의 대형 인라인 블록이다:

| 액션 | 줄 범위 | 줄 수 | 복잡도 |
|------|---------|-------|--------|
| `POST .../approve` | 243~420 | **178줄** | CAS 잠금, 체크리스트 검증, 자동 검증, Marketo 스케줄링 |
| `POST .../cancel` | 549~638 | **90줄** | send_mode 분기, Marketo reschedule, Suppression/SendCap 정리 |
| `POST .../duplicate` | 641~677 | **37줄** | 날짜 계산, 복제 INSERT |

### 변경 사항

대형 인라인 블록을 **같은 파일 하단**에 named function으로 추출한다.
파일 이동 없이 같은 `api/campaigns.php` 내에서 함수로 분리하므로 위험도가 매우 낮다.

#### (a) `handle_approve(string $id, array $approve_body): void`

**추출 범위**: 243~420줄 (`elseif ($method === 'POST' && $id && $action === 'approve')` 블록 내부)

**변경 후 dispatch 블록**:
```php
// POST /api/campaigns/{id}/approve
elseif ($method === 'POST' && $id && $action === 'approve') {
    handle_approve($id, parse_json_body());
}
```

**변경 후 함수 정의** (파일 하단에 추가):
```php
function handle_approve(string $id, array $approve_body): void
{
    $c = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
    if (!$c) json_err('캠페인을 찾을 수 없습니다.', 404);
    if ($c['status'] !== 'awaiting_approval') {
        json_err('결재 대기 상태의 캠페인만 승인할 수 있습니다.', 400);
    }

    // ... (243~420줄의 기존 로직을 그대로 이동) ...
}
```

주의: 현재 `$approve_body`는 블록 내부에서 `parse_json_body()`를 호출한다 (257줄). dispatch에서 미리 파싱하여 인자로 전달하는 방식으로 변경.

#### (b) `handle_cancel(string $id): void`

**추출 범위**: 549~638줄

**변경 후 dispatch 블록**:
```php
// POST /api/campaigns/{id}/cancel
elseif ($method === 'POST' && $id && $action === 'cancel') {
    handle_cancel($id);
}
```

**함수 내부에서** `parse_json_body()` 호출이 필요한 경우 (574줄, `$cancel_body`): 조건부로만 파싱하므로 함수 내부에서 직접 호출을 유지한다.

#### (c) `handle_duplicate(string $id): void`

**추출 범위**: 641~677줄

**변경 후 dispatch 블록**:
```php
// POST /api/campaigns/{id}/duplicate
elseif ($method === 'POST' && $id && $action === 'duplicate') {
    handle_duplicate($id);
}
```

### 변경 후 예시 — dispatch 블록 전체 (간소화)

```php
try {
    // GET /api/campaigns
    if ($method === 'GET' && !$id) {
        json_ok(DB::all('SELECT * FROM campaigns ORDER BY created_at DESC'));
    }
    elseif ($method === 'GET' && $id && !$action) {
        $row = DB::one('SELECT * FROM campaigns WHERE id=?', [$id]);
        if (!$row) json_err('캠페인을 찾을 수 없습니다.', 404);
        json_ok($row);
    }
    elseif ($method === 'GET' && $id && $action === 'logs') { /* 짧은 핸들러 유지 */ }
    elseif ($method === 'GET' && $id && $action === 'previous-cohort') { /* 짧은 핸들러 유지 */ }
    // ...
    elseif ($method === 'POST' && $id && $action === 'approve') {
        handle_approve($id, parse_json_body());
    }
    elseif ($method === 'POST' && $id && $action === 'cancel') {
        handle_cancel($id);
    }
    elseif ($method === 'POST' && $id && $action === 'duplicate') {
        handle_duplicate($id);
    }
    // ... (짧은 핸들러는 인라인 유지)
    else {
        json_err('Not Found', 404);
    }
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}

// ── 핸들러 함수 ─────────────────────────────────────────────────

function handle_approve(string $id, array $approve_body): void { /* ... */ }
function handle_cancel(string $id): void { /* ... */ }
function handle_duplicate(string $id): void { /* ... */ }
function run_test_email_flow(string $id): void { /* ... 기존 위치 유지 */ }
function set_campaign_status(string $id, string $status, ?string $error = null): void { /* ... */ }
function add_log(string $campaign_id, string $step, string $status, string $message): void { /* ... */ }
```

### 주의 사항

1. **`handle_approve()`의 `$c` 변수 재로드**: approve 블록에서 `$c`를 두 번 로드한다 (244줄, 351줄). 추출 후에도 함수 내부에서 동일하게 유지한다.
2. **`CampaignNeedsReviewException` catch**: approve 핸들러 내부의 `run_campaign_schedule()` 호출(405줄)에서 이 예외를 catch한다. 함수 추출 시 이 try/catch를 함수 내부에 포함시켜야 한다.
3. **파일 하단의 기존 함수들**: `run_test_email_flow()` (701줄), `set_campaign_status()` (739줄), `add_log()` (745줄)은 이미 파일 하단에 함수로 분리되어 있다. 새 핸들러 함수는 이들 앞에 배치한다.
4. **`json_ok()`와 `json_err()`의 `exit` 동작**: 두 함수 모두 내부에서 `exit`을 호출하므로, 추출된 함수에서 호출해도 제어 흐름이 올바르게 종료된다. 별도 return 불필요.

---

## 5. 테스트 영향도 분석

### helpers.php 관련 테스트 (작업 #1 영향)

`tests/bootstrap.php` 6줄에서 `require_once __DIR__ . '/../src/helpers.php'`로 로드한다. helpers.php가 서브파일의 require_once 허브가 되므로 **테스트는 자동으로 모든 서브파일을 로드**한다. 수정 불필요.

영향받는 테스트 파일 목록:

| 테스트 파일 | 테스트 대상 함수 | 영향 |
|-------------|------------------|------|
| `tests/Unit/HelpersTest.php` | `is_dry_run()`, `job_log()`, `mask_email_pii()`, `record_status_transition()`, `screenshot_save()`, `check_lead_count_drift()`, `build_where_clause()`, `ensure_run_id()`, `format_send_time_for_marketo()` | **없음** — helpers.php 허브가 전부 require하므로 함수 가용성 동일 |
| `tests/Unit/CampaignFunctionsTest.php` | `build_campaign_tokens()`, `parse_send_time()`, `status_label()`, `status_badge_class()` | **없음** |
| `tests/Unit/TokenVerifyTest.php` | `normalize_token_name()`, `diff_campaign_tokens()`, `build_campaign_tokens()` | **없음** |
| `tests/Unit/CohortTest.php` | `compute_cohort_stats()` | **없음** |

### sanitize_cap_int 중복 제거 (작업 #2 영향)

`sanitize_cap_int()`에 대한 직접적인 단위 테스트는 현재 없다. 기존 테스트는 API 엔드포인트를 직접 호출하지 않고 함수 단위로 테스트하므로 영향 없음.

**확인 필요**: `sanitize_cap_int()`가 `json_err()`를 호출하는데, `json_err()`는 `exit`를 포함한다. 향후 단위 테스트를 추가할 경우 이 함수의 에러 경로를 테스트하려면 process isolation이 필요하다.

### API 보일러플레이트 추출 (작업 #3 영향)

현재 테스트는 API 파일을 직접 require하지 않는다 (API 테스트는 통합 테스트 범위이며 현재 없음). 따라서 **기존 229개 단위 테스트에 영향 없음**.

### campaigns.php 핸들러 추출 (작업 #4 영향)

`api/campaigns.php`를 직접 require하는 테스트는 없다. 파일 내부 함수(`run_test_email_flow`, `set_campaign_status`, `add_log`)를 외부에서 호출하는 테스트도 없다. **영향 없음**.

### 추천 검증 절차

각 작업 완료 후 반드시 다음을 실행한다:

```bash
# 전체 테스트 실행
./vendor/bin/phpunit

# 특히 helpers 관련 테스트 단독 실행
./vendor/bin/phpunit tests/Unit/HelpersTest.php
./vendor/bin/phpunit tests/Unit/CampaignFunctionsTest.php
./vendor/bin/phpunit tests/Unit/TokenVerifyTest.php
./vendor/bin/phpunit tests/Unit/CohortTest.php

# 문법 오류 일괄 체크 (신규 서브파일 포함)
find src/helpers -name '*.php' -exec php -l {} \;
php -l src/helpers.php
php -l src/ApiBase.php
php -l api/segments.php
php -l api/rules.php
php -l api/campaigns.php
```

---

## 부록: 파일 구조 변경 요약

```
src/
  helpers.php              ← require_once 허브만 (함수 정의 없음)
  helpers/                 ← 신규 디렉터리
    http.php               ← json_ok, json_err, parse_json_body
    uuid.php               ← new_uuid, now_str
    security.php           ← assert_readonly, mask_email_pii
    filters.php            ← build_where_clause, cast_filter_value, check_lead_count_drift, get_field_defs, _build_where_*
    tokens.php             ← build_campaign_tokens, normalize_token_name, diff_campaign_tokens, mime/html_*_value
    schedule.php           ← parse_send_time, format_send_time_for_marketo
    kpi.php                ← _kpi_safe_select, kpi_*, kpi_trend_arrow_svg
    logging.php            ← job_log, is_dry_run, ensure_run_id, screenshot_save, record_status_transition, SCREENSHOT_* 상수
    status.php             ← status_label, status_badge_class
    cohort.php             ← compute_cohort_stats
    validation.php         ← sanitize_cap_int (api/segments.php + api/rules.php에서 이동)
  ApiBase.php              ← api_handle() 함수 (신규)

api/
  campaigns.php            ← 인라인 블록을 handle_approve/cancel/duplicate 함수로 추출
  segments.php             ← sanitize_cap_int 정의 삭제
  rules.php                ← sanitize_cap_int function_exists 블록 삭제
```
