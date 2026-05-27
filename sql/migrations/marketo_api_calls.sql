-- sql/migrations/marketo_api_calls.sql
-- PR-4 (δ) — Marketo API 일별·endpoint별 콜 카운터 (2026-05-21)
--
-- 목적: 50K/일 한도 대비 실제 콜 분포 측정. 60K NP 발송 후 endpoint 별 비중 확인,
-- 80% 도달 시 Slack 경보, 향후 Option γ(앱 DB diff) 진행 여부 데이터 기반 결정.
--
-- 카운트 정책:
--   - 모든 MarketoAPI::curl 진입 시 1회 upsert (성공 여부 무관)
--   - retry 도 별도 카운트(실제 콜이 늘었음)
--   - DRY_RUN_MODE 는 미카운트
--   - error_count 는 200 외 응답·throw 모두 합산

USE `marketo_automation`;

CREATE TABLE IF NOT EXISTS `marketo_api_calls` (
  `date`         VARCHAR(10) NOT NULL COMMENT 'YYYY-MM-DD (KST)',
  `endpoint`     VARCHAR(100) NOT NULL COMMENT 'classifyEndpoint 결과: lists.addLeads, activities, ...',
  `count`        INT NOT NULL DEFAULT 0,
  `error_count`  INT NOT NULL DEFAULT 0,
  `last_updated` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`date`, `endpoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
