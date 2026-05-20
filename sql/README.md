# sql/

## 파일 종류

| 파일 | 용도 |
|------|------|
| `schema.sql` | **신규 설치용** — 최신 스키마 한 파일. 빈 DB에 이걸 실행하면 끝. |
| `migrations/*.sql` | **기존 환경 업그레이드용** — 이미 운영 중인 DB에 컬럼/인덱스를 추가. |

## migrations 적용 순서

| 순서 | 파일 | 일자(추정) | 목적 |
|------|------|----|------|
| 1 | `migrations/token_fields.sql` | 2026-04 | campaigns에 `email_title`, `email_preheader` 추가, `send_time` VARCHAR(50) 확장 |
| 2 | `migrations/delivery_tracking.sql` | 2026-04 | `sent_count`, `delivered_count`, `bounce_count`, `poll_*` 컬럼 |
| 3 | `migrations/defaults.sql` | 2026-04 | segments에 `default_*` 기본 발송 설정 컬럼 |
| 4 | `migrations/bulk_import.sql` | 2026-04 | `bulk_job_id`, `bulk_status`, `bulk_started_at` 추적 |
| 5 | `migrations/segment_id_index.sql` | 2026-05 | `campaigns.segment_id` 인덱스 (sibling CAS 성능) |
| 6 | `migrations/approval.sql` | 2026-05-12 | 결재 워크플로 컬럼 + 기존 `test_sent` → `awaiting_approval` 데이터 이행 |
| 7 | `migrations/run_id.sql` | 2026-05-20 | Sprint 0 INFRA — `campaigns.run_id` + `job_logs.run_id` (+인덱스), 발송 1회 추적 UUID |
| 8 | `migrations/status_history.sql` | 2026-05-20 | Sprint 1 INFRA — `status_history` 테이블 신설(상태 전이 감사 로그, 알림·KPI 근거) |
| 9 | `migrations/screenshot.sql` | 2026-05-20 | Sprint 1 ASSET — `campaigns.test_screenshot_path`, 결재 카드 테스트 메일 스크린샷 첨부 슬롯 |

> 일자는 기존 commit history 기준. 신규 환경에서는 `schema.sql` 1회만 실행하면 모든 migration을 반영한 상태가 된다.

## 실행 예시

신규 설치:
```bash
mysql -u root < sql/schema.sql
```

기존 DB 업그레이드:
```bash
mysql -u root marketo_automation < sql/migrations/approval.sql
```

phpMyAdmin에서 파일 import도 동일.
