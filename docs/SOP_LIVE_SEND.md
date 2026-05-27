# SOP_LIVE_SEND.md — 라이브 발송 표준 운영 절차

> 본 문서는 **운영자(마케터)** 가 라이브 발송 시 따라야 하는 표준 절차입니다.
> 2026-05-22 SEV1 사고(잘못된 자산·잘못된 시각 발송) 의 재발 방지를 위해 작성되었습니다.

---

## 0. 시작 전 — 본 시스템의 한계 이해

본 시스템은 *대부분의 위험* 을 자동으로 잡지만, **다음 두 가지는 시스템이 자동 처리할 수 없으니 운영자가 직접 확인** 해야 합니다:

1. **Marketo Smart Campaign Flow 의 Send Email step 자산** — Marketo API 한계로 자동 교체 불가. 발송 전 Marketo UI 에서 직접 확인 + 교체.
2. **Marketo My Token (Title/Emoji/Preheader/RewardUrl)** — 본 시스템이 동기화하지만, 발송 직전 Marketo UI 에서 한 번 더 *눈으로* 확인 권장.

이 두 가지를 skip 하면 다른 모든 자동화 가드가 동작해도 잘못된 이메일이 발송될 수 있습니다.

---

## 1. 라이브 발송 워크플로우

### 1-1. 캠페인 생성/편집
1. 사이드바 "캠페인" → "+신규" 또는 기존 캠페인 → "편집"
2. 필수 입력:
   - **캠페인 이름**: 사후 추적용 (예: `Active-A_2026-05-27_피기뱅크`)
   - **세그먼트**: 대상자 추출 조건이 박힌 세그먼트 선택
   - **이메일 에셋 (수동 교체 기준)**: ← *Marketo UI 에서 SC Flow 에 박을 자산명과 정확히 일치해야 함*
   - **이모지 / 제목 / 프리헤더 / 보상 URL**: My Token 으로 주입될 4 종 값
   - **발송 일시**: KST wall-clock (예: `2026-05-28T10:00`). 입력 즉시 KST → UTC 변환됨.

3. 저장 시:
   - 테스트 메일 자동 발송 (`SEND_TEST_EMAIL_TO` 설정값 수신자에게)
   - 캠페인 상태 → `awaiting_approval`

### 1-2. 테스트 메일 수신 후 즉시 확인
받은 테스트 메일에서:
- [ ] 제목·프리헤더·이모지·보상URL 모두 의도값과 일치
- [ ] *발송 시각* 이 의도한 KST 시각 (테스트 메일 헤더의 `Date:` 확인)

### 1-3. Marketo UI 에서 직접 확인 (필수)
**본 단계 skip 시 SEV1 사고 재발 위험.**

브라우저로 Marketo 접속 → Marketing Activities → 본 발송 Program 펼치기 → Smart Campaign 클릭 → **Flow** 탭 → Send Email 스텝:
- [ ] Email asset 이 캠페인의 "이메일 에셋" 입력값과 정확히 일치
- [ ] 다르면 → Send Email step 더블클릭 → 의도 자산으로 교체 → Save

같은 Program 에서 **My Tokens** 탭 확인:
- [ ] `my.Emoji` = 본 시스템 캠페인 편집의 이모지 값
- [ ] `my.Title` = 본 시스템 캠페인 편집의 이메일 제목
- [ ] `my.Preheader` = 본 시스템 캠페인 편집의 프리헤더
- [ ] `my.RewardUrl` = 본 시스템 캠페인 편집의 보상 URL

### 1-4. 결재 (Approve)
본 시스템 캠페인 상세 페이지 → 결재 카드의 **6 가지 체크박스** 모두 확인 후 체크:

1. ☐ **토큰 4종 값 확인** — 본 시스템 입력값이 정확함
2. ☐ **발송 일시 확인** — 의도한 KST 시각
3. ☐ **대상자 세그먼트 확인** — 추출된 대상자 수가 의도와 일치
4. ☐ **테스트 메일 렌더링 확인** — 받은 테스트 메일에서 모든 요소 정상
5. ☐ **Marketo UI 발송 Program 이메일 에셋 직접 확인** ← **§1-3 의 Flow 자산 일치**
6. ☐ **Marketo UI my.Token 4종 직접 확인** ← **§1-3 의 My Tokens 일치**

> 6 개 모두 체크하지 않으면 "Approve" 버튼이 비활성. 서버측에서도 strict boolean 검증으로 우회 차단.

체크 후 "Approve" 클릭 → 캠페인 상태 → `scheduling` → `bulk_polling` → `scheduled`.

---

## 2. 발송 후 모니터링

### 2-1. 5분 취소 윈도
`scheduled` 상태 진입 후 *발송 시각 - 5분* 전까지 캠페인 상세 페이지 상단에 카운트다운 표시.
- 발송 시각이 잘못된 경우 즉시 "예약 취소" 버튼 클릭.
- 취소 시 본 시스템 + Marketo 양쪽 모두 처리됨 (Smart Campaign 모드는 +2년 후로 reschedule + Marketo UI 수동 정리 권장 메시지 표시).

### 2-2. 발송 후 사후 검증 (자동)
발송 시각 +5분 부터 cron 이 5분 주기로 Marketo Activity API 폴링:
- 실제 발송된 자산명 vs `campaigns.asset_name` 비교
- 불일치 시 자동으로 `needs_manual_review` 격리 + Slack 'crit' 알림

### 2-3. 격리 큐 확인
사이드바 "격리 큐" 또는 직접 `/isolation_queue.php` 접속:
- `needs_manual_review` 상태 캠페인 확인
- 격리 사유 (자산 mismatch / token mismatch / EP 권한 오류 등) 확인
- Marketo UI 에서 직접 조치 후 본 시스템에서 "검토 해제" → `scheduled` 또는 `failed` 로 복원

---

## 3. 비상 시나리오

### 3-1. "이미 발송됐는데 자산이 잘못됨"
1. 즉시 격리 큐에서 `needs_manual_review` 자동 격리 확인
2. Marketo UI 에서 Smart Campaign 의 발송 진행 상태 확인:
   - 부분 발송 상태라면 → Campaign Actions → Abort
   - 전체 발송 완료라면 → 회수 불가. 후속 대응 (사과 메일 등) 결정
3. 본 시스템에서 캠페인 상태 → "검토 해제" 후 `failed` 로 마킹
4. 옵시디언 위키에 회고 기록 (`80_Dev_Logs/marketo-send-automation/YYYYMMDD_사고요약.md`)

### 3-2. "발송 시각이 9시간 어긋남"
- 발송 *이전*: 5분 취소 윈도 안이면 즉시 취소. Marketo UI 에서 SC schedule 직접 제거.
- 발송 *이후*: 회수 불가. 사고 등급 산정 후 대응.

운영자가 입력한 시각이 KST 의도이지만 시스템이 UTC 로 잘못 해석하는 SEV1 사고(2026-05-22) 의 재발을 막기 위해 `format_send_time_for_marketo()` + 회귀 테스트 5종이 도입됨. 정상 환경에서는 9h 어긋남이 *불가능* 하나, `config.php` 의 timezone 설정 누락 시 발생 가능 → 운영 시작 전 `vendor/bin/phpunit` 으로 회귀 가드 통과 확인 필수.

### 3-3. "취소했는데 발송됨"
Smart Campaign 모드의 경우:
- 본 시스템의 "예약 취소" 는 +2년 후로 reschedule 함 (Marketo API 한계).
- 운영자가 Marketo UI 의 schedule 을 *직접 제거* 안 하면 +2년 후 자동 발송 가능 → 발생 시 즉시 Marketo UI 에서 schedule 제거.
- 본 시스템은 cancel 후 `manual_action_required` 메시지로 안내함 — 무시하지 말 것.

### 3-4. "Slack 알림이 너무 많이 와요"
- needs_manual_review 격리 = 'crit' 알림. 1주 5건 초과 시 *시스템 어딘가 깨졌다는 신호*. 무시 금지.
- 알림 채널 조정은 `config.php` 의 `SLACK_WEBHOOK_URL` 변경.

---

## 4. 정기 점검 (월 1회 권장)

- [ ] `vendor/bin/phpunit` — 226 tests 모두 통과
- [ ] `pages/marketo_usage.php` 접속 → Marketo API 일일 콜 카운터가 50K/일 한도의 80% 이하
- [ ] `pages/dashboard_results.php` — 최근 1개월 발송 통계 검토
- [ ] 옵시디언 위키 (`80_Dev_Logs/marketo-send-automation/`) 의 최근 회고 검토

---

## 5. 절대 금지 사항

1. ❌ Marketo UI 에서 직접 Smart Campaign Flow 의 자산을 바꿨다고 본 시스템의 `asset_name` 컬럼을 phpMyAdmin 으로 *임의 수정 금지* — 양쪽 불일치 시 자산 mismatch 격리가 false negative 날 수 있음
2. ❌ `config/config.php` 의 `date_default_timezone_set('Asia/Seoul')` 또는 `APP_INPUT_TIMEZONE` 변경 금지 — SEV1 사고의 직접 원인
3. ❌ `cron/*` 의 lock 파일을 *수동 삭제 금지* — 같은 cron 동시 실행 위험
4. ❌ `MARKETO_SEND_MODE` 를 운영 중에 변경 금지 — 기존 segments 의 `marketo_email_program_id` 가 다른 종류의 리소스로 해석되어 schedule API 깨짐
5. ❌ Marketo 의 *결재된* Email Program 의 자산 교체 금지 — Marketo 가 자동으로 unapprove 시킴. 본 시스템이 다시 approve 시도 시 race
6. ❌ 본 시스템의 `bulk_polling` 또는 `scheduling` 상태에서 캠페인 *삭제 금지* — Marketo 쪽 잡이 살아있을 수 있음. 반드시 cancel 후 삭제

---

## 6. 운영자 지원

- 시스템 오류 발생 시: 옵시디언 위키 `80_Dev_Logs/marketo-send-automation/` 에 최근 회고 검색
- 시스템 코드 수정 필요 시: `docs/HANDOFF.md` 의 외부 개발자 contact
- 긴급 (라이브 사고): Slack 'crit' 채널 + 옵시디언 위키에 즉시 사고 기록
