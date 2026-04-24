# 사내 개발환경 가이드라인

> AI 및 개발자 모두 이 파일을 반드시 먼저 읽을 것.

## 회사 표준 스택

| 구성 요소 | 사양 |
|-----------|------|
| 웹 서버 | Apache (XAMPP 내장) |
| 데이터베이스 | MySQL (XAMPP 내장) |
| 웹 언어 | PHP |
| 로컬 통합 도구 | XAMPP |

## ⚠️ 이 프로젝트의 현재 상태 (결정 보류 중)

**현재 스택: Next.js 16 (TypeScript) + SQLite(앱 DB) + MySQL(사내 사용자 DB)**

회사 표준(PHP + MySQL)과 불일치 상태이며, 아키텍처 방향이 아직 결정되지 않았습니다.

**결정 대기 중인 사항:**
- A) 현재 Next.js 유지 + SQLite → MySQL 교체 (Vercel 배포 문제 해결)
- B) PHP + MySQL로 전체 재작성 (회사 표준 완전 준수)

**결정이 내려지기 전까지:**
- 기존 Next.js/TypeScript 코드를 그대로 유지한다.
- PHP 전환을 가정한 코드 작성 금지.
- DB는 현재 SQLite 그대로 유지한다 (교체 작업은 결정 후 진행).

## 회사 표준 규칙 (결정 후 적용)

아키텍처가 PHP로 확정되면 아래 규칙을 적용한다.

1. **DB는 MySQL만 사용** — SQLite, PostgreSQL 등 금지. XAMPP 내장 MySQL 사용.
2. **서버 언어는 PHP만 사용** — Node.js 등 다른 런타임 기반 백엔드 금지.
3. **웹 서버는 Apache** — XAMPP Apache로 로컬 실행. `.htaccess`로 URL 라우팅.
4. **환경 변수는 설정 파일로 분리** — 연결 정보 하드코딩 금지, `.gitignore` 추가.
