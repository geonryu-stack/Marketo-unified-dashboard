# PHP Rewrite — Phase 1: Foundation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** XAMPP에서 동작하는 PHP+MySQL 앱의 기반(DB, Router, Marketo API, 사내 DB, 공통 헬퍼, HTML 레이아웃)을 완성한다.

**Architecture:** index.php 단일 진입점 + .htaccess 라우팅. PDO 기반 MySQL 싱글턴 2개(앱 DB, 사내 DB). PHP curl 기반 Marketo REST API 래퍼. Bootstrap 5 CDN 기반 서버 렌더링 레이아웃.

**Tech Stack:** PHP 8.x, MySQL (XAMPP), Apache .htaccess, Bootstrap 5 CDN, PDO, cURL

**Spec:** `docs/superpowers/specs/2026-04-24-php-rewrite-design.md`

---

## 파일 맵

| 경로 | 역할 |
|------|------|
| `sql/schema.sql` | MySQL 스키마 + 기본 데이터 시딩 |
| `config/config.example.php` | 연결 정보 템플릿 (git 추적) |
| `config/config.php` | 실제 연결 정보 (gitignore) |
| `.htaccess` | Apache URL → index.php 라우팅 |
| `src/Router.php` | URL 패턴 → 핸들러 매핑 |
| `src/DB.php` | 앱 MySQL PDO 싱글턴 |
| `src/InternalDB.php` | 사내 DB MySQL PDO 싱글턴 (읽기전용) |
| `src/MarketoAPI.php` | Marketo REST API curl 래퍼 |
| `src/helpers.php` | 공통 유틸, SQL 빌더, 승인 토큰 |
| `pages/layout_header.php` | HTML head + navbar |
| `pages/layout_footer.php` | scripts + 닫는 태그 |
| `pages/home.php` | 대시보드 홈 |
| `assets/css/style.css` | 공통 스타일 |
| `index.php` | 진입점 — 설정 로드 + 라우터 실행 |

---

### Task 1: MySQL 스키마 생성

**Files:**
- Create: `sql/schema.sql`

- [ ] **Step 1: schema.sql 생성**

```sql
-- sql/schema.sql
CREATE DATABASE IF NOT EXISTS `marketo_automation`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `marketo_automation`;

CREATE TABLE IF NOT EXISTS `segments` (
  `id` VARCHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT '',
  `filters` TEXT NOT NULL DEFAULT '[]',
  `last_count` INT DEFAULT NULL,
  `last_extracted_at` VARCHAR(50) DEFAULT NULL,
  `marketo_program_id` VARCHAR(100) NOT NULL DEFAULT '',
  `marketo_audience_list_id` VARCHAR(100) NOT NULL DEFAULT '',
  `marketo_email_program_id` VARCHAR(100) NOT NULL DEFAULT '',
  `is_recurring` TINYINT(1) NOT NULL DEFAULT 0,
  `send_day_of_week` INT NOT NULL DEFAULT 1,
  `recurring_send_time` VARCHAR(10) NOT NULL DEFAULT '10:00',
  `created_at` VARCHAR(50) NOT NULL,
  `updated_at` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `campaigns` (
  `id` VARCHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `segment_id` VARCHAR(36) NOT NULL,
  `segment_name` VARCHAR(255) NOT NULL,
  `asset_name` VARCHAR(255) NOT NULL DEFAULT '',
  `reward_url` TEXT NOT NULL DEFAULT '',
  `scheduled_at` VARCHAR(50) NOT NULL,
  `send_time` VARCHAR(10) NOT NULL DEFAULT '',
  `marketo_list_id` VARCHAR(100) DEFAULT NULL,
  `marketo_list_name` VARCHAR(255) DEFAULT NULL,
  `marketo_cloned_email_id` VARCHAR(100) DEFAULT NULL,
  `marketo_email_program_id` VARCHAR(100) DEFAULT NULL,
  `marketo_campaign_id` VARCHAR(100) DEFAULT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'draft',
  `lead_count` INT NOT NULL DEFAULT 0,
  `error_message` TEXT DEFAULT NULL,
  `created_at` VARCHAR(50) NOT NULL,
  `updated_at` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `job_logs` (
  `id` VARCHAR(36) NOT NULL,
  `campaign_id` VARCHAR(36) NOT NULL,
  `step` VARCHAR(100) NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
  `message` TEXT DEFAULT NULL,
  `created_at` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `groups` (
  `id` VARCHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `marketo_campaign_id` INT NOT NULL,
  `marketo_list_id` INT NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `send_schedules` (
  `id` VARCHAR(36) NOT NULL,
  `group_id` VARCHAR(36) NOT NULL,
  `send_date` VARCHAR(20) NOT NULL,
  `marketo_email_id` INT NOT NULL,
  `marketo_email_name` VARCHAR(255) NOT NULL DEFAULT '',
  `send_time` VARCHAR(10) NOT NULL DEFAULT '10:00',
  `timezone` VARCHAR(10) NOT NULL DEFAULT 'RTZ',
  `status` VARCHAR(50) NOT NULL DEFAULT 'draft',
  `test_sent_at` VARCHAR(50) DEFAULT NULL,
  `scheduled_at` VARCHAR(50) DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at` VARCHAR(50) NOT NULL,
  `updated_at` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_group_date` (`group_id`, `send_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `groups` (`id`, `name`, `marketo_campaign_id`, `marketo_list_id`, `sort_order`) VALUES
('active-a',  'Active A',  7610, 8293, 0),
('active-b',  'Active B',  7611, 8294, 1),
('fp-active', 'FP Active', 7613, 8296, 2),
('np-active', 'NP Active', 7612, 8295, 3);
```

- [ ] **Step 2: XAMPP phpMyAdmin에서 schema.sql 실행 확인**

  XAMPP Control Panel → Apache 시작, MySQL 시작 → 브라우저에서 `http://localhost/phpmyadmin` → SQL 탭에 schema.sql 내용 붙여넣기 → 실행.
  `marketo_automation` DB와 5개 테이블이 생성되면 성공.

- [ ] **Step 3: Commit**

```bash
git add sql/schema.sql
git commit -m "feat: MySQL schema for PHP rewrite"
```

---

### Task 2: Config 파일

**Files:**
- Create: `config/config.example.php`
- Create: `config/config.php` (gitignore 대상)

- [ ] **Step 1: config.example.php 생성**

```php
<?php
// config/config.example.php — 이 파일을 config.php로 복사 후 실제 값 입력

// ── 앱 DB (XAMPP MySQL) ───────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'marketo_automation');

// ── 사내 DB (읽기전용) ─────────────────────────────────────────
define('INTERNAL_DB_HOST', '');
define('INTERNAL_DB_PORT', '3306');
define('INTERNAL_DB_USER', '');
define('INTERNAL_DB_PASS', '');
define('INTERNAL_DB_NAME', '');
define('INTERNAL_DB_TABLE', 'users');
define('INTERNAL_DB_EMAIL_FIELD', 'email');

// ── Marketo REST API ──────────────────────────────────────────
define('MARKETO_CLIENT_ID', '');
define('MARKETO_CLIENT_SECRET', '');
define('MARKETO_REST_URL', '');      // e.g. https://xxx.mktorest.com/rest
define('MARKETO_IDENTITY_URL', ''); // e.g. https://xxx.mktorest.com/identity

// ── 앱 설정 ───────────────────────────────────────────────────
define('SEND_TEST_EMAIL_TO', '');   // 쉼표 구분 e.g. a@b.com,c@d.com
define('APP_URL', 'http://localhost/marketo-automation');
define('APPROVAL_SECRET', 'CHANGE_ME_RANDOM_STRING_32_CHARS');

// ── Marketo 토큰 캐시 파일 경로 ──────────────────────────────
define('TOKEN_CACHE_FILE', __DIR__ . '/marketo_token.cache');
```

- [ ] **Step 2: config.php 생성 (실제 값 — .gitignore 대상)**

  `config/config.example.php`를 복사해 `config/config.php`로 저장하고 실제 값 입력.

- [ ] **Step 3: .gitignore에 추가**

  프로젝트 루트 `.gitignore`에 아래 라인 추가:
  ```
  config/config.php
  config/marketo_token.cache
  ```

- [ ] **Step 4: Commit**

```bash
git add config/config.example.php .gitignore
git commit -m "feat: add config template and gitignore rules"
```

---

### Task 3: .htaccess + index.php 진입점

**Files:**
- Create: `.htaccess`
- Create: `index.php`

- [ ] **Step 1: .htaccess 생성**

```apache
# .htaccess
RewriteEngine On
RewriteBase /marketo-automation/

# 실제 파일·디렉터리는 그대로 서빙
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

# 나머지는 index.php로
RewriteRule ^ index.php [QSA,L]
```

- [ ] **Step 2: index.php 생성**

```php
<?php
// index.php — 단일 진입점
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/src/Router.php';
require_once __DIR__ . '/src/DB.php';
require_once __DIR__ . '/src/InternalDB.php';
require_once __DIR__ . '/src/helpers.php';

$router = new Router();

// ── 페이지 라우트 ─────────────────────────────────────────────
$router->add('GET', '/', function ($p) {
    include __DIR__ . '/pages/home.php';
});
$router->add('GET', '/segments', function ($p) {
    include __DIR__ . '/pages/segments/index.php';
});
$router->add('GET', '/segments/new', function ($p) {
    include __DIR__ . '/pages/segments/new.php';
});
$router->add('GET', '/segments/{id}/edit', function ($p) {
    $id = $p['id'];
    include __DIR__ . '/pages/segments/edit.php';
});
$router->add('GET', '/campaigns', function ($p) {
    include __DIR__ . '/pages/campaigns/index.php';
});
$router->add('GET', '/campaigns/new', function ($p) {
    include __DIR__ . '/pages/campaigns/new.php';
});
$router->add('GET', '/campaigns/{id}', function ($p) {
    $id = $p['id'];
    include __DIR__ . '/pages/campaigns/detail.php';
});
$router->add('GET', '/schedules', function ($p) {
    include __DIR__ . '/pages/schedules/index.php';
});

// ── API 라우트 ────────────────────────────────────────────────
$router->add('ANY', '/api/segments', function ($p) {
    require_once __DIR__ . '/api/segments.php';
});
$router->add('ANY', '/api/segments/{id}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/segments.php';
});
$router->add('ANY', '/api/campaigns', function ($p) {
    require_once __DIR__ . '/api/campaigns.php';
});
$router->add('ANY', '/api/campaigns/{id}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/campaigns.php';
});
$router->add('ANY', '/api/campaigns/{id}/{action}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/campaigns.php';
});
$router->add('ANY', '/api/schedules', function ($p) {
    require_once __DIR__ . '/api/schedules.php';
});
$router->add('ANY', '/api/schedules/{id}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/schedules.php';
});
$router->add('ANY', '/api/schedules/{id}/{action}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/schedules.php';
});
$router->add('ANY', '/api/internal-db/{action}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/internal-db.php';
});
$router->add('ANY', '/api/marketo/{resource}', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/marketo.php';
});

// ── 승인/거절 링크 (GET) ──────────────────────────────────────
$router->add('GET', '/campaigns/{id}/approve-via-link', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/campaigns.php';
});
$router->add('GET', '/campaigns/{id}/reject-via-link', function ($p) {
    $GLOBALS['route_params'] = $p;
    require_once __DIR__ . '/api/campaigns.php';
});

// ── 디스패치 ─────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base));
}
$uri = '/' . ltrim($uri, '/');

$router->dispatch($method, $uri);
```

- [ ] **Step 3: 브라우저에서 404 확인**

  XAMPP Apache 실행 후 `http://localhost/marketo-automation/nonexistent` 접속.
  JSON `{"success":false,"error":"Not Found"}` 가 나오면 라우팅 동작 확인.
  (pages/home.php가 아직 없으므로 `/`는 에러여도 됨)

- [ ] **Step 4: Commit**

```bash
git add .htaccess index.php
git commit -m "feat: Apache htaccess routing and index.php entry point"
```

---

### Task 4: Router.php

**Files:**
- Create: `src/Router.php`

- [ ] **Step 1: Router.php 생성**

```php
<?php
// src/Router.php
declare(strict_types=1);

class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => $regex,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $uri = '/' . trim($uri, '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method) && $route['method'] !== 'ANY') {
                continue;
            }
            if (preg_match('#^' . $route['regex'] . '$#', $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                ($route['handler'])($params);
                return;
            }
        }

        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not Found']);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Router.php
git commit -m "feat: simple URL router"
```

---

### Task 5: DB.php + InternalDB.php

**Files:**
- Create: `src/DB.php`
- Create: `src/InternalDB.php`

- [ ] **Step 1: DB.php 생성**

```php
<?php
// src/DB.php
declare(strict_types=1);

class DB
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST, DB_PORT, DB_NAME
            );
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$instance;
    }

    /** UPDATE/INSERT 편의 메서드 — affected rows 반환 */
    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** SELECT 단일 행 반환 — 없으면 null */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** SELECT 전체 행 반환 */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
```

- [ ] **Step 2: InternalDB.php 생성**

```php
<?php
// src/InternalDB.php
declare(strict_types=1);

class InternalDB
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                INTERNAL_DB_HOST, INTERNAL_DB_PORT, INTERNAL_DB_NAME
            );
            self::$instance = new PDO($dsn, INTERNAL_DB_USER, INTERNAL_DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$instance;
    }

    /** SELECT 전용 실행 — CONSTRAINT-01 */
    public static function query(string $sql, array $params = []): array
    {
        assert_readonly($sql);  // helpers.php의 assert_readonly 사용
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/DB.php src/InternalDB.php
git commit -m "feat: MySQL PDO singletons for app DB and internal DB"
```

---

### Task 6: helpers.php

**Files:**
- Create: `src/helpers.php`

- [ ] **Step 1: helpers.php 생성**

```php
<?php
// src/helpers.php
declare(strict_types=1);

// ── HTTP 응답 ─────────────────────────────────────────────────

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

// ── UUID, 날짜 ────────────────────────────────────────────────

function new_uuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function now_str(): string
{
    return (new DateTime())->format('Y-m-d H:i:s');
}

// ── 보안: SQL 읽기전용 강제 ──────────────────────────────────

function assert_readonly(string $sql): void
{
    $normalized = strtoupper(ltrim($sql));
    if (!str_starts_with($normalized, 'SELECT') && !str_starts_with($normalized, 'WITH')) {
        throw new RuntimeException('CONSTRAINT-01: 사내 DB는 SELECT 쿼리만 허용됩니다.');
    }
}

// ── 세그먼트 필드 정의 (FIELD_DEFS 이식) ─────────────────────

function get_field_defs(): array
{
    return [
        ['field' => 'email',               'label' => '이메일',                  'type' => 'text'],
        ['field' => 'user_id',             'label' => '사용자 ID',               'type' => 'text'],
        ['field' => 'country',             'label' => '국가',                    'type' => 'select',
         'options' => ['KR','US','JP','TW','TH','VN','PH']],
        ['field' => 'platform',            'label' => '플랫폼',                  'type' => 'select',
         'options' => ['ios','android']],
        ['field' => 'language',            'label' => '언어',                    'type' => 'select',
         'options' => ['ko','en','ja','zh','th','vi']],
        ['field' => 'days_since_login',    'label' => '마지막 로그인 경과일 (일)', 'type' => 'number',
         'sql_expr' => 'DATEDIFF(NOW(), last_login_at)'],
        ['field' => 'days_since_register', 'label' => '가입 후 경과일 (일)',      'type' => 'number',
         'sql_expr' => 'DATEDIFF(NOW(), created_at)'],
        ['field' => 'total_purchase_count','label' => '총 결제 횟수',             'type' => 'number'],
        ['field' => 'total_purchase_amount','label' => '총 결제 금액',            'type' => 'number'],
        ['field' => 'days_since_purchase', 'label' => '마지막 결제 경과일 (일)',  'type' => 'number',
         'sql_expr' => 'DATEDIFF(NOW(), last_purchase_at)'],
        ['field' => 'is_active',           'label' => '활성 상태',               'type' => 'boolean'],
        ['field' => 'user_level',          'label' => '사용자 레벨',             'type' => 'number'],
        ['field' => 'marketing_consent',   'label' => '마케팅 수신 동의',         'type' => 'boolean'],
    ];
}

// ── SQL WHERE 빌더 (buildWhereClause 이식) ───────────────────

function build_where_clause(array $filters, array $field_defs): array
{
    if (empty($filters)) {
        return ['sql' => '1=1', 'params' => []];
    }

    $def_map = array_column($field_defs, null, 'field');
    $clauses = [];
    $params  = [];

    foreach ($filters as $f) {
        if (!isset($def_map[$f['field']])) {
            throw new RuntimeException(
                "알 수 없는 필터 필드: '{$f['field']}'. 세그먼트 편집 화면에서 해당 조건을 제거하거나 유효한 필드로 교체하세요."
            );
        }
        $def = $def_map[$f['field']];
        $col = isset($def['sql_expr']) ? $def['sql_expr'] : '`' . $def['field'] . '`';
        $op  = $f['operator'];

        switch ($op) {
            case '=': case '!=': case '>': case '>=': case '<': case '<=':
                $clauses[] = "$col $op ?";
                $params[]  = cast_filter_value($f['value'], $def['type']);
                break;
            case 'IN': case 'NOT IN':
                $vals = array_values(array_filter(array_map('trim', explode(',', $f['value']))));
                if (empty($vals)) continue 2;
                $ph = implode(', ', array_fill(0, count($vals), '?'));
                $clauses[] = "$col $op ($ph)";
                foreach ($vals as $v) {
                    $params[] = cast_filter_value($v, $def['type']);
                }
                break;
            case 'LIKE':
                $clauses[] = "$col LIKE ?";
                $params[]  = '%' . $f['value'] . '%';
                break;
            case 'IS NULL':
                $clauses[] = "$col IS NULL";
                break;
            case 'IS NOT NULL':
                $clauses[] = "$col IS NOT NULL";
                break;
        }
    }

    return [
        'sql'    => empty($clauses) ? '1=1' : implode(' AND ', $clauses),
        'params' => $params,
    ];
}

function cast_filter_value(string $value, string $type): mixed
{
    if ($type === 'number') return is_numeric($value) ? $value + 0 : $value;
    if ($type === 'boolean') return ($value === 'true' || $value === '1') ? 1 : 0;
    return $value;
}

// ── 승인 토큰 (HMAC-SHA256) ───────────────────────────────────

function generate_approval_token(string $action, string $campaign_id, int $expires_at): string
{
    $payload = "$action:$campaign_id:$expires_at";
    return hash_hmac('sha256', $payload, APPROVAL_SECRET);
}

function verify_approval_token(string $token, string $action, string $campaign_id, int $expires_at): bool
{
    if (time() > $expires_at) return false;
    $expected = generate_approval_token($action, $campaign_id, $expires_at);
    return hash_equals($expected, $token);
}

// ── 캠페인 상태 한국어 레이블 ─────────────────────────────────

function status_label(string $status): string
{
    return [
        'draft'             => '초안',
        'confirmed'         => '확인 완료',
        'extracting'        => 'DB 추출 중',
        'uploading'         => '업로드 중',
        'preparing'         => '테스트 발송 중',
        'awaiting_approval' => '승인 대기',
        'scheduling'        => '예약 설정 중',
        'scheduled'         => '예약 완료',
        'cancelling'        => '예약 취소 중',
        'sent'              => '발송 완료',
        'failed'            => '실패',
    ][$status] ?? $status;
}

function status_badge_class(string $status): string
{
    return [
        'draft'             => 'secondary',
        'confirmed'         => 'primary',
        'extracting'        => 'warning',
        'uploading'         => 'warning',
        'preparing'         => 'warning',
        'awaiting_approval' => 'info',
        'scheduling'        => 'primary',
        'scheduled'         => 'success',
        'cancelling'        => 'secondary',
        'sent'              => 'success',
        'failed'            => 'danger',
    ][$status] ?? 'secondary';
}

// ── JSON 바디 파싱 ─────────────────────────────────────────────

function parse_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_err('Invalid JSON body', 400);
    }
    return $data ?? [];
}

// ── 이메일 발송 (nodemailer 대체 — PHP mail / SMTP) ──────────

function send_approval_email(array $campaign, string $approve_url, string $reject_url): void
{
    $test_emails = array_filter(array_map('trim', explode(',', SEND_TEST_EMAIL_TO)));
    if (empty($test_emails)) return;

    $subject = "[Marketo Automation] 캠페인 승인 요청: {$campaign['name']}";
    $body = "캠페인: {$campaign['name']}\n"
          . "상태: " . status_label($campaign['status']) . "\n\n"
          . "✅ 승인: $approve_url\n\n"
          . "❌ 거절: $reject_url\n";

    $headers = "From: no-reply@marketo-automation\r\nContent-Type: text/plain; charset=UTF-8";

    foreach ($test_emails as $email) {
        @mail($email, $subject, $body, $headers);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/helpers.php
git commit -m "feat: helpers — json response, UUID, SQL builder, approval tokens"
```

---

### Task 7: HTML 레이아웃

**Files:**
- Create: `pages/layout_header.php`
- Create: `pages/layout_footer.php`
- Create: `assets/css/style.css`

- [ ] **Step 1: layout_header.php 생성**

```php
<?php
// pages/layout_header.php
// 사용 전 $title 변수 설정 필요 (없으면 기본값 사용)
$page_title = ($title ?? 'Marketo Automation') . ' — Marketo Automation';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($page_title) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
  <div class="container-fluid px-4">
    <a class="navbar-brand fw-bold" href="<?= APP_URL ?>">Marketo Automation</a>
    <div class="navbar-nav flex-row gap-3">
      <a class="nav-link <?= (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/marketo-automation/segments')) ? 'active fw-bold' : '' ?>"
         href="<?= APP_URL ?>/segments">세그먼트</a>
      <a class="nav-link <?= (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/marketo-automation/campaigns')) ? 'active fw-bold' : '' ?>"
         href="<?= APP_URL ?>/campaigns">캠페인</a>
      <a class="nav-link <?= (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/marketo-automation/schedules')) ? 'active fw-bold' : '' ?>"
         href="<?= APP_URL ?>/schedules">발송 스케줄</a>
    </div>
  </div>
</nav>
<div class="container-fluid px-4">
```

- [ ] **Step 2: layout_footer.php 생성**

```php
<?php
// pages/layout_footer.php
?>
</div><!-- /container -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($scripts)): ?>
  <?php foreach ($scripts as $src): ?>
    <script src="<?= APP_URL ?>/assets/js/<?= htmlspecialchars($src) ?>"></script>
  <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
```

- [ ] **Step 3: style.css 생성**

```css
/* assets/css/style.css */
body { background-color: #f8f9fa; }
.navbar-brand { font-size: 1rem; }
.table th { white-space: nowrap; }
.badge { font-size: 0.8em; }
.filter-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
.filter-row select, .filter-row input { min-width: 160px; }
.log-row-done    { color: #198754; }
.log-row-error   { color: #dc3545; }
.log-row-running { color: #0d6efd; }
```

- [ ] **Step 4: pages/home.php 생성 (임시 홈)**

```php
<?php
// pages/home.php
$title = '홈';
include __DIR__ . '/layout_header.php';
?>
<h2>Marketo Automation Dashboard</h2>
<div class="row mt-4 g-3">
  <div class="col-md-4">
    <a href="<?= APP_URL ?>/segments" class="card text-decoration-none text-dark">
      <div class="card-body text-center">
        <h5 class="card-title">세그먼트</h5>
        <p class="card-text text-muted">발송 대상자 필터 관리</p>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="<?= APP_URL ?>/campaigns" class="card text-decoration-none text-dark">
      <div class="card-body text-center">
        <h5 class="card-title">캠페인</h5>
        <p class="card-text text-muted">이메일 캠페인 실행 및 추적</p>
      </div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="<?= APP_URL ?>/schedules" class="card text-decoration-none text-dark">
      <div class="card-body text-center">
        <h5 class="card-title">발송 스케줄</h5>
        <p class="card-text text-muted">주간 발송 일정 관리</p>
      </div>
    </a>
  </div>
</div>
<?php include __DIR__ . '/layout_footer.php'; ?>
```

- [ ] **Step 5: 브라우저 확인**

  `http://localhost/marketo-automation/` 접속 → Bootstrap 스타일이 적용된 홈 대시보드가 보이면 성공.

- [ ] **Step 6: Commit**

```bash
git add pages/layout_header.php pages/layout_footer.php pages/home.php assets/css/style.css
git commit -m "feat: HTML layout with Bootstrap 5 and dashboard home"
```

---

### Task 8: MarketoAPI.php

**Files:**
- Create: `src/MarketoAPI.php`

- [ ] **Step 1: MarketoAPI.php 생성**

```php
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
            $ids_str = implode(',', $chunk);
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
        // campaign 배열에서 My Token 페이로드 구성
        // 실제 필드 이름은 Phase 2 캠페인 구현 시 segment의 token 설정 기반으로 채워짐
        return array_filter([
            !empty($campaign['token_image'])    ? ['name' => $campaign['token_image'],    'value' => $campaign['image_url'] ?? '',    'type' => 'HTML'] : null,
            !empty($campaign['token_subject'])  ? ['name' => $campaign['token_subject'],  'value' => ($campaign['emoji'] ?? '') . ' ' . ($campaign['subject'] ?? ''), 'type' => 'text'] : null,
            !empty($campaign['token_preheader'])? ['name' => $campaign['token_preheader'],'value' => $campaign['preheader'] ?? '',    'type' => 'text'] : null,
            !empty($campaign['token_body'])     ? ['name' => $campaign['token_body'],     'value' => $campaign['body_text'] ?? '',    'type' => 'text'] : null,
            !empty($campaign['token_emoji'])    ? ['name' => $campaign['token_emoji'],    'value' => $campaign['emoji'] ?? '',        'type' => 'text'] : null,
            !empty($campaign['token_reward_url'])? ['name' => $campaign['token_reward_url'],'value' => $campaign['reward_url'] ?? '', 'type' => 'text'] : null,
        ]);
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
```

- [ ] **Step 2: Marketo API 연결 테스트**

  `config/config.php`에 실제 Marketo 정보 입력 후:
  ```php
  // test_marketo.php (임시 — 테스트 후 삭제)
  <?php
  require_once 'config/config.php';
  require_once 'src/helpers.php';
  require_once 'src/MarketoAPI.php';
  $token = MarketoAPI::getAccessToken();
  echo $token ? "✅ 토큰 발급 성공: $token" : "❌ 실패";
  ```
  브라우저에서 `http://localhost/marketo-automation/test_marketo.php` 실행.
  성공 확인 후 test_marketo.php 삭제.

- [ ] **Step 3: Commit**

```bash
git add src/MarketoAPI.php
git commit -m "feat: Marketo REST API curl wrapper"
```

---

### Task 9: API 엔드포인트 — internal-db.php + marketo.php

**Files:**
- Create: `api/internal-db.php`
- Create: `api/marketo.php`

- [ ] **Step 1: api/internal-db.php 생성**

```php
<?php
// api/internal-db.php
declare(strict_types=1);
require_once __DIR__ . '/../src/InternalDB.php';

$action = $GLOBALS['route_params']['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// GET /api/internal-db/fields
if ($action === 'fields' && $method === 'GET') {
    json_ok(get_field_defs());
}

// POST /api/internal-db/preview
elseif ($action === 'preview' && $method === 'POST') {
    try {
        $body    = parse_json_body();
        $filters = $body['filters'] ?? [];
        ['sql' => $where, 'params' => $params] = build_where_clause($filters, get_field_defs());

        $table   = INTERNAL_DB_TABLE;
        $sql     = "SELECT COUNT(*) AS cnt FROM `$table` WHERE $where";
        assert_readonly($sql);

        $rows  = InternalDB::query($sql, $params);
        $count = (int)($rows[0]['cnt'] ?? 0);
        json_ok(['count' => $count]);
    } catch (Throwable $e) {
        json_err($e->getMessage());
    }
}

else {
    json_err('Not Found', 404);
}
```

- [ ] **Step 2: api/marketo.php 생성**

```php
<?php
// api/marketo.php
declare(strict_types=1);
require_once __DIR__ . '/../src/MarketoAPI.php';

$resource = $GLOBALS['route_params']['resource'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];

try {
    if ($resource === 'emails' && $method === 'GET') {
        json_ok(MarketoAPI::getEmailList());
    } elseif ($resource === 'lists' && $method === 'GET') {
        json_ok(MarketoAPI::getStaticLists());
    } elseif ($resource === 'campaigns' && $method === 'GET') {
        json_ok(MarketoAPI::getSmartCampaigns());
    } else {
        json_err('Not Found', 404);
    }
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}
```

- [ ] **Step 3: 브라우저 확인**

  `http://localhost/marketo-automation/api/internal-db/fields` → field 배열 JSON 반환 확인.

- [ ] **Step 4: Commit**

```bash
git add api/internal-db.php api/marketo.php
git commit -m "feat: internal-db and marketo proxy API endpoints"
```

---

### Task 10: GitHub 푸시

- [ ] **Step 1: 전체 상태 확인 및 푸시**

```bash
git status
git push origin main
```

- [ ] **Step 2: 최종 확인**

  XAMPP에서 `http://localhost/marketo-automation/` → 홈 대시보드 정상 표시.  
  `http://localhost/marketo-automation/api/internal-db/fields` → JSON 배열 정상 반환.  
  Phase 1 완료.
