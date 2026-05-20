# PIPELINE.md — 캠페인 1건의 정규 파이프라인

> 13개 스테이지로 재정립. 각 스테이지는 **입력 / 처리 / 출력 / 실패모드 / 책임자**를 명시.
> 위치 표기: `파일.php:관심부` 형식.

## 0. 흐름 한눈에 보기

```
[S1] Segment 정의 ──────────► segments 테이블 (정적 메타)
                                      │
                                      ▼
[S2] Campaign Draft 생성 ───► campaigns(status=draft)
                                      │
                                      ▼
[S3] Token Asset Library 주입 ─► Marketo Library Program (My Token)
                                      │
                                      ▼
[S4] Test Email 발송 ─────────► Marketo sendSample API
                                      │
                                      ▼
[S5] awaiting_approval ◄─── 운영자 검토 + 4종 체크리스트
                                      │
                                      ▼
[S6] 결재 승인 (CAS) ────────► scheduling
                                      │
                ┌─────────────────────┴────────────────────┐
                ▼                                          ▼
   [S7a] REST 경로  (≤10K)                  [S7b] Bulk 경로 (>10K)
   - upsertLeads                            - list 비우기
   - list 비우기 + 신규 추가                - CSV bulk POST
                                            - status=bulk_polling
                                                   │
                                                   ▼
                                       [S8] check_bulk_imports cron
                                            - Complete & failed=0 시
                                              status=bulk_finalizing (CAS)
                ▼                                          ▼
                └─────────────────────┬────────────────────┘
                                      ▼
[S9]  Token 발송 Program 주입 (Asset Library와 별개의 send Program)
[S10] EP unapprove (safe — 709 무시)
[S11] EP scheduleEmailProgram (RTZ) ─► status=scheduled
                                      │   ※ S9-S11 = "EP 변경 위험구간"
                                      │     실패 시 needs_manual_review 격리
                                      ▼
[S12] sync_sent_campaigns cron ──► status=sent (send_time+30m)
                                      │   poll_status=polling
                                      ▼
[S13] check_sent_activities cron ──► sent/delivered/bounce count
                                      종료 조건: 8h 또는 95% coverage
```

---

## S1. Segment 정의

| 항목 | 값 |
|------|----|
| 입력 | 운영자: 필터 조건, Program ID, Audience List ID, Email Program ID |
| 처리 | `pages/segments/new.php` → `api/segments.php` |
| 출력 | `segments` row 생성 |
| 산출 | DB-only. 외부 호출 없음. |
| 실패 모드 | 필터가 비면 400 (전체 사용자 발송 방지) |
| 책임자 | DB-segment 담당 (필드 정의 = `src/helpers.php:get_field_defs`) |

## S2. Campaign Draft 생성

| 항목 | 값 |
|------|----|
| 입력 | name, segment_id, asset_name, reward_url, emoji, title, preheader, send_time, marketo_cloned_email_id |
| 처리 | `api/campaigns.php` POST → INSERT |
| 출력 | `campaigns` row (status=`draft`), `scheduled_at = send_time - 16h` 자동계산 |
| 사전조건 | send_time ≥ 현재 + 16h (INV-03) |
| 실패 모드 | 400 — send_time 검증 실패 |
| 책임자 | asset-composer 담당 |

## S3. Token Asset Library 주입 (테스트용)

| 항목 | 값 |
|------|----|
| 위치 | `api/campaigns.php:run_test_email_flow` |
| API | `MarketoAPI::syncProgramMyTokens(MARKETO_EMAIL_ASSET_LIBRARY_ID, …)` |
| 4개 토큰 | Emoji(MIME), Title(MIME), Preheader(HTML 엔티티), RewardUrl(원본) |
| 우회 | Marketo Email Program API 610 → 프로그램 폴더 레벨 POST로 우회 |
| 실패 모드 | 토큰 실패 시 status=failed, 캠페인은 보존(편집 후 재시도) |
| 책임자 | asset-composer / Marketo 담당 |

## S4. Test Email 발송

| 항목 | 값 |
|------|----|
| API | `MarketoAPI::sendSampleEmail(emailId, addr)` |
| 수신자 | `SEND_TEST_EMAIL_TO` (쉼표 구분 다중) |
| 후행 | 성공 시 status=`awaiting_approval` |
| 실패 모드 | 5xx/네트워크 오류 시 재시도 금지 (중복 발송 방지) |
| 책임자 | asset-composer |

## S5. 결재 대기 (휴먼 검증)

| 항목 | 값 |
|------|----|
| UI | `pages/campaigns/detail.php` 결재 카드 |
| 게이팅 | 4종 체크박스(토큰/시각/대상/렌더) + type-to-confirm |
| 분기 | 승인 → S6, 거절 → draft 복귀 (`reject_memo`) |
| 책임자 | 운영자 (인간) |

## S6. 결재 승인 (CAS Lock)

| 항목 | 값 |
|------|----|
| 위치 | `api/campaigns.php:approve` |
| 잠금 | `SELECT … WHERE segment_id=? ORDER BY id FOR UPDATE` |
| 차단 상태 | `scheduled / scheduling / bulk_polling / bulk_finalizing / needs_manual_review` |
| 전환 | `awaiting_approval` → `scheduling` (CAS 1 row만) |
| 실패 모드 | 409 (sibling 충돌 / 상태 변경) |
| 책임자 | 운영자 + campaign-orchestrator |

## S7a. REST 경로 (≤ BULK_THRESHOLD)

| 항목 | 값 |
|------|----|
| 위치 | `ScheduleRunner.php:run_rest_path` |
| 단계 | (a) `extract_campaign_leads` → (b) `upsertLeads` → (c) `getListLeadIds + remove + add` |
| 후행 | `finalize_campaign_schedule` 즉시 호출 (동기) |
| 책임자 | campaign-orchestrator + Marketo 담당 |

## S7b. Bulk 경로 (> BULK_THRESHOLD)

| 항목 | 값 |
|------|----|
| 위치 | `ScheduleRunner.php:run_bulk_path` |
| 단계 | (a) extract → (b) list 비우기 → (c) `submitBulkImport(CSV)` |
| 출력 | status=`bulk_polling`, `bulk_job_id` 저장 |
| 후행 | 비동기 — S8 cron이 이어받음 |
| 실패 모드 | POST 5xx → 즉시 throw (중복 잡 방지) |
| 책임자 | Marketo 담당 (api), 인프라(cron) |

## S8. Bulk 폴링 cron

| 항목 | 값 |
|------|----|
| 위치 | `cron/check_bulk_imports.php` (1분 주기) |
| 분기 | `Importing/Queued` → 대기 / `Failed` → status=failed / `Complete` 분기 |
| CAS | `bulk_polling → bulk_finalizing` (1 row만 잡음) |
| Critic | `numOfRowsFailed > 0` 자동 진행 차단 (INV-05) |
| 후행 | `finalize_campaign_schedule` 호출 |
| 책임자 | 인프라 + Marketo 담당 |

## S9. Token 발송 Program 주입 (위험구간 진입)

| 항목 | 값 |
|------|----|
| 위치 | `ScheduleRunner.php:finalize_campaign_schedule` |
| API | `syncProgramMyTokens(seg.marketo_program_id, …)` |
| 사전저장 | `marketo_email_program_id`를 DB에 미리 기록 (INV-06) |
| 안전성 | 토큰만 → EP 미변경 → 실패 시 'failed'로 풀어도 안전 (위험구간 진입 전) |

## S10. EP Unapprove

| 항목 | 값 |
|------|----|
| API | `unapproveEmailProgramSafe(ep_id)` |
| 안전 코드 | 709 (이미 draft) → `already_draft` 반환, 정상 |
| 위험성 | POST → 5xx/네트워크 끊김 시 재시도 금지. EP 상태 불확정 가능. |

## S11. EP Schedule (RTZ)

| 항목 | 값 |
|------|----|
| API | `scheduleEmailProgram(ep_id, sendDtUtc, recipientTimeZone=true)` |
| 시각 포맷 | `Y-m-d\TH:i:sZ` (UTC). 각 수신자 현지시각으로 발송. |
| 성공 시 | status=`scheduled` |
| 실패 시 | status=`needs_manual_review` + `CampaignNeedsReviewException` (INV-04) |

## S12. Sent 전환 cron

| 항목 | 값 |
|------|----|
| 위치 | `cron/sync_sent_campaigns.php` (5~10분 주기) |
| 조건 | status=scheduled AND `send_time + 30m ≤ now()` |
| 전환 | status=`sent`, poll_status=`polling`, poll_started_at=now |

## S13. Activity 폴링 cron

| 항목 | 값 |
|------|----|
| 위치 | `cron/check_sent_activities.php` (5분 주기) |
| API | `getEmailActivities(listId, since=send_time-24h)` |
| 활동 ID | 6=Sent, 7=Delivered, 11/12=Bounce |
| 동적 인터벌 | <60m → 5m / <240m → 15m / 그 외 60m |
| 종료 | 8h 경과 또는 sent ≥ 95% × lead_count |
| 종료 상태 | coverage ≥ 0.9 → `done` / 미달 → `timeout` |

---

## 부록 A. 상태 머신 (campaigns.status)

```
draft ──► (test_email_flow) ──► awaiting_approval ──► scheduling
                                      │                    │
                                      ▼ (reject)           │
                                    draft                  │
                                                           ▼
                                   bulk_polling ◄─── (Bulk 경로)
                                           │
                                           ▼ (CAS)
                                  bulk_finalizing
                                           │
                                           ▼ (S9-S11)
                              ┌─────► scheduled ──► sent
                              │             ▲           │ (poll)
                              │             │           ▼
                              │             └── (cancel-not-supported  done | timeout
                              │                  -from-bulk: needs review)
                              │
                       needs_manual_review (운영자: resolve-review)
                              │
                              ├─► scheduled (확인됨)
                              └─► failed   (정리됨, sibling 차단 해제)

  실패 경로:
      어디서든 throw → status=failed (편집 후 재시도 가능)
                                                   └─► 단, finalize 위험구간(S9-S11) 실패는 needs_manual_review로 격리
```

## 부록 B. Cron 주기 권장값

| Cron | 주기 | 워커 동시성 | 비고 |
|------|------|------------|------|
| `check_bulk_imports.php` | 1분 | 1 | LIMIT 10. Marketo Bulk 통상 1~5분. |
| `sync_sent_campaigns.php` | 5분 | 1 | sent 전환 + polling 시작 |
| `check_sent_activities.php` | 5분 | 1 | LIMIT 10. 동적 인터벌(`poll_next_at`)이 실제 폴링 빈도 결정. |

> 2026-05-20 정리: `run_due_campaigns.php`(결재 워크플로 도입 후 status=test_sent 사라짐),
> `check_sent_campaigns.php`(sync_sent_campaigns의 열등 변종)는 제거됨. Windows Task
> Scheduler 등록에서도 삭제할 것.
