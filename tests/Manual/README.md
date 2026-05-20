# tests/Manual/

PHPUnit suite와 분리된 **수동 라이브 테스트**. 실제 Marketo API와 통신하므로 자동
CI에 포함하지 않는다.

## 실행
```bash
/Applications/XAMPP/xamppfiles/bin/php tests/Manual/test_marketo_retry.php
```

(Windows XAMPP: `C:\xampp\php\php.exe tests\Manual\test_marketo_retry.php`)

## 포함된 시나리오
- 토큰 캐시 만료 시 재발급
- 정상 GET 호출 (회귀 검증)
- 네트워크 오류 → GET 백오프 재시도(2+4+8s)
- POST는 네트워크 오류에 즉시 fail (재시도 없음, 부작용 중복 방지)
- HTTP 5xx 표면화 (httpbin.org 의존)

## 사전 조건
`config/config.php`의 Marketo 자격증명이 유효해야 한다. 자격증명이 잘못되면 첫 단계에서 종료된다.
