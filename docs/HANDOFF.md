# HANDOFF.md — 외부 개발자 인수인계 핵심 결정사항

> 본 문서는 2026-05-27 기준, 본 프로젝트를 외부 개발자에게 핸드오프하면서 *현재 코드만 읽어서는 알기 어려운* 결정·배경·남은 결함을 정리한 것입니다. `DEPLOYMENT.md` 를 먼저 따른 후, 본 문서로 *왜 그렇게 짰는지* 를 이해하세요.

---

## 1. 본 시스템이 해결하는 문제

### 1-1. 본질
대상자 추출 (사내 DB) → Marketo Static List 업로드 → My Token 동기화 → Smart Campaign 예약 → 발송 결과 Activity 폴링 → 격리/재시도 일체를 **운영자 1명이 안전하게 수행**할 수 있도록 자동화.

### 1-2. 핵심 제약
1. **사내 DB 는 SELECT only (CONSTRAINT-01)** — `src/InternalDB.php::assert_readonly` 에서 강제. UPDATE/INSERT/DELETE 자동 차단.
2. **Marketo Smart Campaign Flow API 불완전** — Send Email step 의 자산 자동 교체 불가. 운영자가 Marketo UI 에서 수동 교체 후 본 시스템이 사후 검증.
3. **운영자 = 마케터, 비-개발자** — 모든 위험 분기는 자동 격리 + Slack alert. 운영자가 SQL/CLI 만지지 않아도 동작 가능해야 함.

---

## 2. 최근 SEV1 사고 (2026-05-22) 와 4 가지 픽스

운영자 의도 'Smash The Piggy' 자산 대신 'The Clock Keeps Moving' 자산이 발송, 동시에 KST 10:00 의도가 실제 KST 19:00 도착 (9h 어긋남).

### 4 root cause + fix
| RC | 영향 | Fix 위치 |
|---|---|---|
| #1 타임존 변환 누락 | KST 입력 → UTC 'Z' literal 부착 → 9h 어긋남 | `src/helpers.php::format_send_time_for_marketo()` — `DateTimeImmutable + DateTimeZone` 명시 변환 |
| #2 자산 자동 교체 불가 | Marketo SC Flow 가 의도 외 자산 가리킴 | `cron/check_sent_activities.php` — Activity API 사후 검증 + `needs_manual_review` 자동 격리. `MarketoAPI::detectAssetNameMismatch()` (`array_diff` 기반, mixed 케이스 차단) |
| #3 결재 게이트 client-only | DevTools 로 disabled 제거하면 우회 | `api/campaigns.php` approve 분기 — 서버측 6 키 strict boolean 검증 |
| #4 UI 라벨 부족 | 운영자가 "이메일 에셋 자동 교체됨" 으로 오인 | `pages/campaigns/{new,edit}.php` 의 경고 박스 — "수동 교체 기준" 명시 |

### 회귀 가드
`tests/Unit/HelpersTest.php` 의 `testFormatSendTimeForMarketo*` 5종, `tests/Unit/SentAssetNameTest.php` 15종, `tests/Unit/MarketoApiSpecGuardTest.php` 8종.

---

## 3. 2026-05-26 ~ 27 추가 검수 결함과 픽스

본 sprint 의 막판에 marketo-sync-agent 와 code-reviewer 가 독립 검수하여 **추가 16건 결함** 발견·수정. 핸드오프 직전 직접 처리한 항목들:

| 분류 | 항목 | Fix 위치 |
|---|---|---|
| spec | Folder Tokens API `folderType` required 누락 | `MarketoAPI::syncFolderMyTokens` 4번째 인자 `$folderType='Folder'` 추가 |
| spec | Bulk Import status URL 잘못된 `/status.json` suffix | `MarketoBulkImport::getBulkImportStatus` — `/bulk/v1/leads/batch/{id}.json` (suffix 제거) |
| spec | Bulk status response `numOfLeadsProcessed` fallback 누락 | `MarketoBulkImport::computeProgress` — 공식 spec 키를 첫 fallback 으로 |
| CRIT C1 | `getSmartCampaigns()` 이중 `/rest/v1/` prefix → 영구 404 | `/v1/campaigns.json` 으로 수정 |
| CRIT C2 | `cron/check_sent_activities.php` since 9h 어긋남 (RC#1 cron 미적용) | `format_send_time_for_marketo` + `gmdate(...UTC)` 적용 |
| CRIT C-1 | cancel path SC 모드 fake-cancel | `MarketoAPI::rescheduleSmartCampaignFarFuture()` 신설 (+2년 후로 reschedule), reschedule 실패 시 502 + DB 상태 유지로 fake-cancel 차단 |
| CRIT C-2 | detail.php countdown 이 SEV1 RC#1 안티패턴 재발 | `format_send_time_for_marketo` 통일 |
| CRIT Codex | UI 가 cancel acknowledgement gate 처리 못함 | `assets/js/campaign.js::cancel()` 2단계 흐름 (1차 시도 → 409 + requires_acknowledgement → confirm 다이얼로그 → 재시도) |
| HIGH H1 | `removeLeadsFromList` DELETE+body → WAF strip 위험 (SEV1급) | `POST + ?_method=DELETE` 표준 패턴으로 변경 |
| HIGH H-1 | `MARKETO_SEND_MODE` config.example.php 누락 | 상세 주석과 함께 추가 |
| HIGH H2 | `curlRaw` 직접 호출 3곳의 602 token 미갱신 | `curlRawWithTokenRefresh()` wrapper 도입 |
| HIGH H3 | 백오프 `[2,4,8]s` < window 20s, Retry-After 무시 | `[5,15,30]s` 로 상향 + `decideBackoffSeconds()` 헬퍼로 Retry-After 우선 사용 |
| HIGH H-2 | SendCap DB 메서드 unit test 0 | `SendCapSqlGuardTest` 7개 SQL 안티패턴 정적 가드 + 핸드오프 후 통합 테스트 구축 권장 |
| HIGH H-3 | cancel 시 이미 sent 박제 N명 경고 없음 | `api/campaigns.php` 409 + `acknowledge_sent=true` 명시적 confirmation 게이트 |
| MED | asset mismatch 검사가 listId 24h 윈도우의 sibling 캠페인 sent 까지 포함 → false-positive 격리 | `extractSentEmailAssetNamesForCampaign()` 신설 — 'Campaign ID'/'Mailing ID' attribute 로 본 캠페인 sent 만 필터 |
| 운영주의 | Activity API `listId` 10K+ deprecation (2026-12-30) | 코드 변경 없음. 본 문서 §5 에 명시 |

---

## 4. 알려진 Marketo API 한계 (코드로 우회 불가)

### 4-1. Smart Campaign Flow Send Email step 자동 교체 불가
- Marketo public REST 에 SC Flow step 수정 API 없음.
- **우회**: 본 시스템은 `campaigns.asset_name` 컬럼 = *운영자가 Marketo UI 에서 수동 교체할 자산명의 기준*. 발송 후 Activity API 로 사후 검증 + mismatch 시 자동 격리.
- **운영 SOP**: `docs/SOP_LIVE_SEND.md` 의 6 키 체크리스트의 5번째 항목 (Marketo UI 직접 확인) 절대 skip 금지.

### 4-2. Scheduled Smart Campaign 의 cancel API 없음
- Marketo: "there isn't a way to actually deactivate a scheduled smart campaign via API".
- **우회**: `rescheduleSmartCampaignFarFuture()` — `runAt` 을 +2년 후로 변경해 de-facto cancel. 운영자에게 UI 수동 schedule 제거 권장 메시지 표시.
- **위험**: reschedule 자체 실패 시 (Marketo 응답 오류) DB 상태 `scheduled` 그대로 유지 + 502 응답으로 fake-cancel 차단.

### 4-3. Email Program POST 권한 차단 (610 'Access Denied')
- 일부 Marketo 인스턴스가 EP POST 권한 미허가.
- **우회**: `MARKETO_SEND_MODE='smart_campaign'` 기본값. SC 의 schedule API 는 권한 차단 안 됨.

### 4-4. Activity API `listId` 10K+ deprecation (2026-12-30)
- Marketo 공식 deprecation: 2026-12-30 이후 listId 가 10,000+ leads 인 경우 Activity API 호출 시 error 1003 fail.
- **현재 영향**: 60K 발송 환경에서 본 PR 직후엔 OK. 7개월 후 깨짐.
- **대안 (핸드오프 후 외부 개발자가 구현)**:
  - Bulk Activity Extract API 마이그레이션 (`/bulk/v1/activities/export/...`)
  - 또는 `leadIds` 30 청크 페이지네이션 (API 호출 비용 증가)
- **우선순위**: 2026-12-30 이전에 반드시 처리. v2 PR 후보.

---

## 5. 남은 결함 (Med 6 + Low 9 = 15건)

본 sprint 에서 *코드 변경 없이* 핸드오프 문서로 미룬 항목들. 라이브 정상 path 영향 없는 robustness 항목이라 핸드오프 후 외부 개발자가 별도 PR 로 처리 가능.

### Medium
- **M1**: `cron/check_bulk_imports.php` 의 unknown-status 무한 폴링 — `bulk_started_at + 60min` 초과 시 자동 'failed' 격리 권장
- **M2**: OAuth 토큰 캐시 read race — 현재 602 retry 가 자가 치유. 주석 정정만 필요
- **M3**: Suppression `DATE(c.send_time) = ?` non-sargable — 인덱스 추가 또는 range 비교로 변환
- **M4**: Bulk POST 4xx (606/615/602 외) 즉시 throw — retry 슬롯 낭비. 구조 개선
- **M5**: `attachLeadIds` CASE WHEN 에 `ELSE lead_id` 방어 추가
- **M-act-type**: `ENGAGEMENT_TYPE_IDS` config override 미반영 — `engagementTypeIds()` 진입부에 `defined('MARKETO_ACTIVITY_IDS')` 분기 추가
- **M-paging-ttl**: Activity paging token 30일 만료 시 1003 자동 재발급 분기 없음

### Low
- **L1**: `api/segments.php` 의 `LIMIT $limit` 직접 보간 (이미 int clamp 됨 — style)
- **L2**: `record_status_transition` 이 DELETE 분기에서 누락
- **L3**: cancel JS 가 빈 body — 본 sprint 에서 수정 완료
- **L4**: `pages/isolation_queue.php` 의 EP/SC 라벨 모호
- **L5**: `scheduleEmailProgram` 의 `recipientTimeZone` 기본 true — EP 가 RTZ 미설정이면 422 가능. 호출자가 명시 전달하도록 변경 권장
- **L6**: `getListLeadIds` `batchSize=300` — const 추출
- **L7**: `curlRaw` 의 4xx 응답이 errors 빈 경우 표면화 안 됨 — `if (http_code >= 400 && empty(errors))` 분기 추가
- **L8**: Bulk POST `CURLOPT_TIMEOUT=120s` — 9MB CSV 의 느린 회선 대응 위해 300s 권장
- **L9**: `verify_schedule_echo` 가 SC 모드에서 미호출 — SC 용 echo 검증 헬퍼 신설 또는 명시적 limitation 기록

---

## 6. 운영 가드 (자동 작동 — 손대지 말 것)

| 가드 | 위치 | 역할 |
|---|---|---|
| 사내 DB SELECT-only | `src/InternalDB.php::assert_readonly` | UPDATE/INSERT/DELETE 자동 throw |
| 결재 5단 게이트 (서버측) | `api/campaigns.php` approve | 6 키 strict `=== true` 검증 (DevTools 우회 차단) |
| 동시 cron 실행 가드 | 각 cron 의 `cron/_lock.php` 또는 advisory lock | 같은 cron 2 instance 동시 실행 차단 |
| 자산 mismatch 자동 격리 | `cron/check_sent_activities.php` | 의도 외 자산 발송 즉시 `needs_manual_review` |
| Bulk CSV 10MB 사전검증 | `MarketoBulkImport::buildCsv` | 한도 도달 전 throw |
| Bulk Import status 멱등성 | `check_bulk_imports.php` | status='Complete' 후 재호출 시 noop |
| VVIP Suppression NOT IN | `src/Suppression.php` | 같은 날 일반 캠페인이 VVIP 추출 대상 제외 |
| Lead Cap hold/confirm 박제 | `src/SendCap.php` | 같은 이메일 일/주 단위 cap 위반 차단 |
| 라이브 OAuth race 방지 | `MarketoAPI::getAccessToken` 의 LOCK_EX | 다중 워커 토큰 충돌 차단 |
| dry-run 킬스위치 | `is_dry_run()` | `DRY_RUN_MODE=true` 면 모든 POST/DELETE no-op |

---

## 7. 회귀 테스트 정책

- **신규/수정 코드는 반드시 phpunit 테스트 추가**. 본 sprint 에서 도입한 회귀 가드 패턴:
  - 정적 안티패턴 검사: `MarketoApiSpecGuardTest`, `SendCapSqlGuardTest` (코드의 *모양* 자체에 박힌 안티패턴)
  - 순수함수 회귀 가드: `HelpersTest::testFormatSendTimeForMarketo*`, `SentAssetNameTest` 등
  - 통합 테스트 미구축: DB-bound SQL 의미 검증. 핸드오프 후 외부 개발자가 SQLite 인메모리 또는 분리된 MySQL test DB 로 구축 권장.
- **테스트 실행**:
  ```bash
  vendor/bin/phpunit
  ```
- **기대치 (2026-05-27)**: **226 tests / 586 assertions OK**

---

## 8. 변경 시 체크리스트

코드 변경 후 *반드시* 다음 모두 통과:

- [ ] `vendor/bin/phpunit` — 모두 통과
- [ ] `php -l` — 수정된 파일 전체 syntax check
- [ ] 변경이 Marketo API path 또는 HTTP method 라면 → `MarketoApiSpecGuardTest` 가 회귀 잡아내는지 확인
- [ ] 변경이 SendCap SQL 이라면 → `SendCapSqlGuardTest` 추가 케이스 보강
- [ ] 발송 흐름 변경이라면 → `docs/SOP_LIVE_SEND.md` 의 6 키 체크리스트 영향 검토
- [ ] CRIT/HIGH 결함 fix 라면 → 옵시디언 위키 (`~/Documents/Obsidian_Master_Wiki/80_Dev_Logs/marketo-send-automation/`) 에 회고 기록

---

## 9. 진입점 빠른 참조

| 파일 | 역할 |
|---|---|
| `index.php` | 모든 HTTP 요청의 라우터 (`pages/`, `api/`) |
| `src/ScheduleRunner.php` | 캠페인 1건의 전체 발송 흐름 오케스트레이션 (13 stages) |
| `src/Marketo/MarketoAPI.php` | Marketo REST 전 호출 (895 LOC) |
| `src/Marketo/MarketoBulkImport.php` | 대용량 CSV 발송 |
| `src/SendCap.php` | 리드별 cap (priority 차등) |
| `src/Suppression.php` | VVIP 우선순위 suppression |
| `cron/run_due_schedules.php` | scheduled_at 도달 시 발송 트리거 |
| `cron/check_bulk_imports.php` | Bulk Import 폴링 |
| `cron/check_sent_activities.php` | 발송 결과 Activity 폴링 + 자산 mismatch 격리 |
| `cron/cleanup_lead_send_history.php` | 30일 초과 cap 박제 정리 |

---

## 10. 다음 단계 제안 (외부 개발자 인계 후 30 일)

1. **W1**: 본 시스템 1주 운영 + 로그 모니터링 (Slack 알림 통과 여부 확인)
2. **W2**: DB-bound 통합 테스트 환경 구축 (분리된 MySQL test DB + Schema 재생성 helper)
3. **W3**: §5 의 Med/Low 15건 우선순위 정리 후 일괄 PR
4. **W4**: Activity API `listId` 10K+ 마이그레이션 시작 (2026-12-30 deadline)

각 단계의 PR 은 `docs/architecture/CRITICS.md` 의 게이트 통과 후 머지.
