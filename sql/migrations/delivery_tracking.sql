-- sql/migrations/delivery_tracking.sql
-- campaigns 테이블에 발송 결과 추적 컬럼 추가
-- phpMyAdmin에서 실행 또는: mysql -u root marketo_automation < sql/migrations/delivery_tracking.sql

USE `marketo_automation`;

ALTER TABLE `campaigns`
  ADD COLUMN `sent_count`         INT         NOT NULL DEFAULT 0           AFTER `lead_count`,
  ADD COLUMN `delivered_count`    INT         NOT NULL DEFAULT 0           AFTER `sent_count`,
  ADD COLUMN `bounce_count`       INT         NOT NULL DEFAULT 0           AFTER `delivered_count`,
  ADD COLUMN `poll_status`        VARCHAR(20) NOT NULL DEFAULT 'idle'
    COMMENT 'idle|polling|done|timeout'                                    AFTER `bounce_count`,
  ADD COLUMN `poll_started_at`    DATETIME    DEFAULT NULL                 AFTER `poll_status`,
  ADD COLUMN `poll_next_at`       DATETIME    DEFAULT NULL                 AFTER `poll_started_at`,
  ADD COLUMN `activity_polled_at` DATETIME    DEFAULT NULL                 AFTER `poll_next_at`;
