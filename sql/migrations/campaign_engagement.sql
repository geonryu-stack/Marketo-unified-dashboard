-- sql/migrations/campaign_engagement.sql
-- 발송 결과 누적·대시보드 MVP (2026-05-21)
--
-- Marketo Ops + Data Analyst 합동 권고:
--   - L1(집계): campaigns 테이블에 open/click/unsubscribe + last_activity_at 추가
--   - L2(시계열): campaign_daily_stats 신규 테이블 — 회차 비교 / 시간 추이 분석
--   - L3(raw events): MVP 보류 (운영자 일상 가치 낮음)
--
-- API 한도 영향: 페이지 수 ~2.3배 증가하나 일 한도의 0.3% 수준 — 안전.

USE `marketo_automation`;

-- L1 — campaigns 테이블 컬럼 추가
ALTER TABLE `campaigns`
  ADD COLUMN `open_count`        INT NOT NULL DEFAULT 0 AFTER `bounce_count`,
  ADD COLUMN `click_count`       INT NOT NULL DEFAULT 0 AFTER `open_count`,
  ADD COLUMN `unsubscribe_count` INT NOT NULL DEFAULT 0 AFTER `click_count`,
  ADD COLUMN `last_activity_at`  VARCHAR(50) DEFAULT NULL
    COMMENT 'open/click 트리클 종료 판단용 — 신규 활동이 1h 없으면 종료 후보' AFTER `unsubscribe_count`;

-- L2 — 시계열 테이블 (캠페인 × 활동 날짜)
CREATE TABLE IF NOT EXISTS `campaign_daily_stats` (
  `campaign_id`       VARCHAR(36) NOT NULL,
  `stat_date`         VARCHAR(10) NOT NULL COMMENT 'YYYY-MM-DD — Marketo activityDate ISO 의 날짜 prefix 그대로 (timezone 정규화 안 함)',
  `sent`              INT NOT NULL DEFAULT 0,
  `delivered`         INT NOT NULL DEFAULT 0,
  `bounce`            INT NOT NULL DEFAULT 0,
  `open`              INT NOT NULL DEFAULT 0,
  `click`             INT NOT NULL DEFAULT 0,
  `unsubscribe`       INT NOT NULL DEFAULT 0,
  `updated_at`        VARCHAR(50) NOT NULL,
  PRIMARY KEY (`campaign_id`, `stat_date`),
  KEY `idx_stat_date` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
