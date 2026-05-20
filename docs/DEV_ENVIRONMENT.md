# 사내 개발환경 가이드라인

> AI 및 개발자 모두 이 파일을 반드시 먼저 읽을 것.

## 회사 표준 스택

| 구성 요소 | 사양 |
|-----------|------|
| 웹 서버 | Apache (XAMPP 내장) |
| 데이터베이스 | MySQL (XAMPP 내장) |
| 웹 언어 | PHP |
| 로컬 통합 도구 | XAMPP |

## 현재 프로젝트 상태

**현재 스택: PHP 8.x + MySQL (XAMPP) + Apache**

PHP 전면 재작성 완료 (2026-04-24). 회사 표준 스택과 완전히 일치합니다.

## 회사 표준 규칙 (적용 중)

1. **DB는 MySQL만 사용** — SQLite, PostgreSQL 등 금지. XAMPP 내장 MySQL 사용.
2. **서버 언어는 PHP만 사용** — Node.js 등 다른 런타임 기반 백엔드 금지.
3. **웹 서버는 Apache** — XAMPP Apache로 로컬 실행. `.htaccess`로 URL 라우팅.
4. **환경 변수는 설정 파일로 분리** — `config/config.php` (gitignore 처리). 연결 정보 하드코딩 금지.
5. **XAMPP 설정** — DocumentRoot에서 `marketo-automation/` 폴더 접근. `http://localhost/marketo-automation/`

## 로컬 실행 방법

1. XAMPP Control Panel → Apache, MySQL 시작
2. `C:\xampp\htdocs\marketo-automation\config\config.example.php` → `config.php` 복사 후 값 입력
3. phpMyAdmin → `sql/schema.sql` 실행 (DB 및 테이블 생성)
4. 브라우저에서 `http://localhost/marketo-automation/` 접속
