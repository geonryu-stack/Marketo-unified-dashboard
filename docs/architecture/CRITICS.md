# CRITICS.md — 단계 간 비평/검증 게이트

> **크리틱**(critic) = 스테이지 출력 → 다음 스테이지 입력 사이의 **반대편 시점에서의 검증**.
> 하네스가 "지키는 것"이라면, 크리틱은 "의심하고 따지는 것".
> 자동(정량) / 반자동(임계치) / 수동(휴먼) 3종으로 분류.

## 0. 크리틱 5원칙

1. **사전조건이 아닌 사후검증** — 가드(`HARNESS.md` A)는 차단, 크리틱은 의심·재현
2. **빠르게 실패 OR 격리** — 의심스러우면 진행 멈추고 운영자에게 양보
3. **숫자로 말함** — "이상하다"가 아니라 "기대 = X, 실측 = Y, 편차 = Z%"
4. **롤백 가능성** — 크리틱은 통과/차단/격리 3택. 부분진행은 가능한 한 금지.
5. **사람 우대** — 마지막 결정권은 늘 운영자

---

## 1. 13스테이지 × 크리틱 매핑

| 직전 단계 → 다음 단계 | 크리틱 ID | 종류 | 행동 |
|----------------------|----------|------|------|
| S1 → S2 | C-SEG-PREVIEW | 자동 | 필터 조건 미리보기 카운트 (`/api/internal-db/preview`) |
| S2 → S3 | C-INPUT-SANITY | 자동 | URL/이모지/제목/프리헤더 길이·문자셋 검증 |
| S3 → S4 | C-TOKEN-ECHO | 반자동 | sample 후 운영자 인박스 확인 |
| S4 → S5 | C-RENDER-OK | 수동 | 4종 체크리스트 (현 구현됨) |
| S5 → S6 | C-FRESHNESS | 자동 | send_time 16h 검증 (현 구현됨, 사전 게이트) + 신규: scheduled_at < now 검증 |
| S6 → S7 | C-SEG-CONFLICT | 자동 | sibling FOR UPDATE (현 구현됨) |
| Extract (S7 내부) | C-LEAD-COUNT | **신규** 반자동 | `lead_count` 0 또는 기대치 대비 편차 > 50% → 일시 차단 |
| S7a/b → S8/S9 | C-LIST-INTEGRITY | **신규** 자동 | REST 경로: Static List 크기와 leads 수가 일치하는가 |
| S8 Complete | C-BULK-PARTIAL | 자동 | `numOfRowsFailed > 0` → 차단 (현 구현됨, INV-05) |
| S9 | C-TOKEN-VERIFY | **신규** 자동 | 주입 후 `getProgramTokens()`로 4개 토큰 값 검증 (echo-back) |
| S10 | C-EP-STATE | 자동(현재 부분) | 709는 정상, 그 외는 격리 |
| S11 | C-SCHEDULE-ECHO | **신규** 자동 | 예약 직후 EP status가 `scheduled`인지 GET으로 재확인 |
| S11 (위험구간 실패) | C-NEEDS-REVIEW | 자동 | `CampaignNeedsReviewException` 격리 (현 구현됨) |
| S12 | C-SEND-WINDOW | 자동 | send_time + 30m CAS (현 구현됨) |
| S13 | C-COVERAGE | 자동 | 8h 또는 95% 도달 → 종료. coverage < 90% → `timeout` |
| S13 종료후 | C-DELIVERY-SANITY | **신규** 반자동 | `(sent + bounce) / lead_count`가 0.7 미만이면 알림 |

> **신규** 표시는 권고 구현. 나머지는 현재 코드에 존재.

---

## 2. 자동 크리틱 상세

### C-SEG-PREVIEW (S1→S2)
- **위치**: `pages/segments/new.php` → `/api/internal-db/preview`
- **체크**: SQL을 동일 WHERE로 `SELECT COUNT(*)` 실행
- **임계**: 0건 → 경고 표시. 100만건+ → 운영자 재확인 UI
- **권고 추가**: 직전 7일치 일자별 추세를 표시하면 "필터가 갑자기 0건"을 더 빨리 발견

### C-INPUT-SANITY (S2→S3) **신규**
```
- email_title: ≤ 100자 (일부 클라이언트 절단)
- email_preheader: ≤ 140자
- reward_url: http(s):// 시작 + URL 파싱 통과
- emoji: 1 grapheme cluster
```
구현 위치 제안: `api/campaigns.php` POST/save 직전.

### C-LEAD-COUNT (S7) **신규**
- 추출 후 `lead_count` 0 → 즉시 throw (현 구현됨)
- 직전 동일 segment 캠페인 `lead_count` 평균 대비 ±50% 편차 → status=`needs_manual_review`로 격리
- 의도: 사내 DB 스키마 변경/필터 정의 오류로 인한 *조용한 폭주/실종* 차단

### C-LIST-INTEGRITY (S7a) **신규**
- REST 경로 종료 직후 `getListLeadIds(list_id)` 호출
- `count(actual) != count(leads)` → throw → status=failed
- 의도: list refresh 도중 부분 실패 검출

### C-BULK-PARTIAL (S8) — 구현됨
- `cron/check_bulk_imports.php:78` — `failed > 0` → status=failed, 운영자 안내
- 부분 발송 차단 (INV-05)

### C-TOKEN-VERIFY (S9) **신규**
- `MarketoAPI::getProgramTokens($send_program_id)` 호출
- 4개 키(Emoji/Title/Preheader/RewardUrl) 값이 `build_campaign_tokens` 결과와 일치하는가
- 미일치 → throw (위험구간 진입 전이므로 'failed'로 풀어도 안전)
- 의도: Marketo 폴더 동기화 race / 캐시 / 권한 이슈 조기 검출

### C-SCHEDULE-ECHO (S11) **신규**
- `scheduleEmailProgram` 호출 직후 EP 정보 GET
- 예상 `scheduledAt` 시각과 실제 응답 시각이 일치하는가 (분 단위)
- 불일치 → status=`needs_manual_review`
- 의도: API 200 응답이지만 실제로는 미예약된 경우(드물지만 발생) 검출

### C-COVERAGE (S13) — 구현됨
- 8h 또는 95% → 종료. `coverage ≥ 0.9` → `done`, 아니면 `timeout`
- `cron/check_sent_activities.php:64`

### C-DELIVERY-SANITY (S13 종료후) **신규**
- 폴링 종료 시 `(sent + bounce) / lead_count < 0.7` → Slack 알림
- 의도: Marketo Activity API 누락 / Static List 변조 / lead 비활성 등의 silent failure 알림

---

## 3. 반자동 크리틱 (임계치 + 사람 확인)

### C-TOKEN-ECHO (S3→S4)
- 운영자가 테스트 메일을 실제로 받아 시각 확인
- 게이팅: "테스트 메일 렌더링 확인" 체크박스 (현 구현)

### C-RENDER-OK (S4→S5)
- 4종 체크리스트 (토큰값 / 발송시각 / 대상자 / 렌더) — 현 구현
- type-to-confirm 모달

---

## 4. 휴먼 크리틱

### C-RESOLVE-REVIEW (S11 실패 후)
- `pages/campaigns/detail.php` 카드에서 운영자가 Marketo UI 확인 후 결정:
  - "Scheduled로 표시" → sibling 차단 유지 (EP 보호)
  - "Failed로 표시" → sibling 차단 해제 (EP 정리됨)
- `error_message`에 결정 누적 — 흔적 보존

### C-CANCEL-INTEGRITY (사후)
- 운영자가 `scheduled` 캠페인 취소 시 EP `unapprove` 자동
- `marketo_email_program_id` 비었을 때 fake cancel 방지 안내(현 구현)

---

## 5. 크리틱 우선순위 (구현 권고 순서)

| 우선 | 크리틱 | 비용 | 효과 |
|------|--------|------|------|
| ★★★ | C-TOKEN-VERIFY | 1콜 추가 | Marketo 토큰 사일런트 미반영 = 잘못된 발송 직결 |
| ★★★ | C-LEAD-COUNT 편차 | DB 조회 | 사내 DB 스키마 드리프트 → 조용한 폭주 차단 |
| ★★☆ | C-SCHEDULE-ECHO | 1콜 추가 | API 200이지만 미예약 검출 |
| ★★☆ | C-INPUT-SANITY | 0콜 | UX + 토큰 깨짐 예방 |
| ★☆☆ | C-LIST-INTEGRITY | 1+콜 | REST 경로 부분실패 검출 |
| ★☆☆ | C-DELIVERY-SANITY | 0콜 | 사후 알림 (이미 발송 끝남) |

---

## 6. 크리틱 호출 위치 권고 패치

| 크리틱 | 추가할 함수/위치 |
|--------|-----------------|
| C-INPUT-SANITY | `helpers.php:assert_campaign_input(array $body)` — POST/save에서 호출 |
| C-LEAD-COUNT | `ScheduleRunner.php:extract_campaign_leads` 끝부분 — 직전 평균 비교 (segments에 last_count 활용) |
| C-LIST-INTEGRITY | `ScheduleRunner.php:run_rest_path` 끝부분 — `getListLeadIds(list_id)` 후 카운트 비교 |
| C-TOKEN-VERIFY | `ScheduleRunner.php:finalize_campaign_schedule` S9 직후 — `getProgramTokens()` 비교 |
| C-SCHEDULE-ECHO | 동일 함수 S11 직후 — EP GET 후 scheduledAt 비교 |
| C-DELIVERY-SANITY | `cron/check_sent_activities.php` 폴링 종료 분기 — coverage 분석 후 알림 |

---

## 7. 크리틱이 못 하는 것 (한계)

- **Marketo UI의 비공개 정책 변경** (예: 일 발송 한도, 도메인 평판 강등) — 외부 모니터링 필요
- **사내 DB의 의미적 변경** (예: `is_active`의 정의가 바뀜) — 운영자가 segment 정의를 재검토
- **수신자 측 변화** (인박스 카테고라이저, ISP 차단) — A/B 가설검증 별도 영역

위 영역은 *크리틱 책임범위 밖*임을 명시. 운영자/마케터의 정성적 판단이 필수.
