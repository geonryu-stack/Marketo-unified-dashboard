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
