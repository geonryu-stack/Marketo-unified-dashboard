# Marketo 발송 자동화 — 비즈니스 제약사항 & 운영 규칙

> 이 파일은 모든 에이전트 및 개발 작업의 최우선 참조 문서입니다.
> 새로운 제약사항이 확인되면 이 파일에 추가합니다.

---

## CONSTRAINT-01: 사내 DB 읽기 전용

- 사내 DB는 **백업 DB**로만 활용 가능하며, `SELECT` 쿼리만 허용됩니다.
- `INSERT`, `UPDATE`, `DELETE`, `DDL` 등 쓰기 작업은 **절대 불가**.
- DB 연결은 읽기 전용 계정(`INTERNAL_DB_USER`)만 사용해야 합니다.
- 추출된 데이터는 앱 내부 SQLite(`data/app.db`)에만 저장합니다.

## CONSTRAINT-02: 보상 URL 수동 발행 필수

- 이메일에 심어지는 인앱 보상 URL은 시스템이 **자동 생성 불가**.
- 담당자가 수동으로 URL을 발행한 뒤, 웹 UI의 지정 입력란에 붙여넣어야 합니다.
- 시스템은 입력된 URL을 에셋 템플릿의 `{{REWARD_URL}}` 위치에 자동으로 치환합니다.
- URL 유효성 검사: `https://` 또는 인앱 딥링크 스킴 허용.

## CONSTRAINT-03: 발송 방식 선택 (Clone / Token)

### Clone 모드 (기본)
- 이전 회차 에셋을 **Clone** → 보상 URL 치환(`{{REWARD_URL}}`) → 드래프트 승인 → Smart Campaign 예약
- 에셋 라이브러리의 `marketo_email_id`로 Clone 소스를 지정합니다.
- 각 발송 회차마다 Marketo에 별도 이메일 에셋이 생성됩니다.

### Token 모드 (신규)
- 에셋 Clone 없이 `scheduleCampaign()` API의 `tokens[]` 파라미터로 이미지·문구·URL을 주입합니다.
- **사전 조건**: Marketo 이메일 템플릿에 My Token(`{{my.xxx}}`)이 미리 설정되어 있어야 합니다.
- 에셋 라이브러리에서 각 My Token 이름을 등록합니다 (예: `{{my.imageUrl}}`, `{{my.rewardUrl}}`).
- **효과 트래킹**: Marketo의 `Campaign Performance Report`에서 회차별(실행일 기준) 통계 확인 가능.
- 이 앱의 `campaigns` 테이블에 회차별 에셋 정보(이미지 URL, 문구, 발송 일시)가 기록됩니다.

### 공통: Marketo 폴더 정리
- 세그먼트에 `marketo_program_id`를 등록하면 발송 시 Static List가 해당 Program 안에 생성됩니다.
- Marketing Activities의 기존 세그먼트별 폴더 구조(폴더 > Program > Smart Campaign)와 매핑합니다.

## CONSTRAINT-04: 신규 에셋 생성 시 전체 교체 필요

- 신규 에셋 생성을 선택한 경우, 아래 모든 요소를 새 내용으로 교체해야 합니다:
  - 이메일 메인 이미지
  - 이메일 제목 (타이틀 + 이모지)
  - 프리헤더 (preheader) 텍스트
  - 이메일 본문 텍스트
  - 보상 URL
- 에셋 라이브러리에 이미지별 텍스트 세트를 미리 저장해두는 방식으로 운영합니다.

## CONSTRAINT-05: Marketo는 발송 엔진 전용

- Marketo 자체 DB 또는 Smart List로는 발송 대상자 자동화가 **불가능**합니다.
- 발송 대상자 리스트는 반드시 사내 DB에서 추출한 데이터를 기반으로 해야 합니다.
- Marketo는 오직 **이메일 발송 엔진**으로만 활용합니다.
- 발송 방식: Static List에 리드를 업로드 → Smart Campaign으로 발송.

## CONSTRAINT-06: Marketo API 제한

- Rate Limit: **100 calls / 20초**. 초과 시 자동 재시도(backoff) 적용.
- 리드 업로드 배치 크기: **최대 300명/요청**.
- Marketo Static List는 캠페인마다 새로 생성하거나 기존 것을 재사용 가능.

## CONSTRAINT-08: 테스트 메일 확인 후 담당자 승인 필수

- 모든 발송은 **Phase 1 (자동)** 완료 후 반드시 담당자가 테스트 메일을 확인하고 명시적으로 승인해야만 예약됩니다.
- Phase 1 완료 시 캠페인 상태가 `awaiting_approval`로 변경되며 자동 대기합니다.
- 담당자 승인 전 확인 항목:
  1. 오탈자 없음
  2. 이미지 정상 표시
  3. 보상 URL 클릭 및 보상 획득 확인
- 테스트 메일 수신 주소: 환경변수 `SEND_TEST_EMAIL_TO`에 고정 설정.
- 문제 발견 시 "재검토" 버튼으로 `draft` 상태로 복귀 → 내용 수정 후 재실행.

## CONSTRAINT-07: 이메일 발송 전 필수 확인 단계

캠페인 실행 전 담당자가 아래 항목을 반드시 확인해야 합니다:
1. 발송 대상자 수 및 세그먼트 조건
2. 에셋 미리보기 (치환된 URL 포함)
3. 발송 예약 일시
4. Marketo 에셋/캠페인 연결 여부

---

## 운영 참고 사항

- 사내 DB 기본 테이블: `users` (환경변수 `INTERNAL_DB_TABLE`로 변경 가능)
- 이메일 필드: `email` (환경변수 `INTERNAL_DB_EMAIL_FIELD`로 변경 가능)
- SQLite 로컬 상태 파일 경로: `data/app.db`
- Marketo Munchkin ID: 환경변수 `MARKETO_MUNCHKIN_ID`
