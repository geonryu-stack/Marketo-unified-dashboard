<?php
// src/helpers/http.php — HTTP 응답 + JSON 바디 파싱
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

// 반환 계약: 항상 array. 다음 케이스를 모두 *400 으로 거부* (5xx 차단):
//   - JSON 파싱 실패 ('Invalid JSON body')
//   - 유효하지만 top-level 이 array 아닌 값 (string / number / boolean / null) ('JSON body must be an object')
// json_err() 는 내부에서 exit 하므로 본 함수 반환 후 호출자는 array 만 보장받음.

function parse_json_body(): array
{
    $raw = file_get_contents('php://input');
    // strict 빈 비교 — empty() 함정 회피.
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
