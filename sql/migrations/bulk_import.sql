-- sql/migrations/bulk_import.sql
-- campaigns 테이블에 Bulk Import 진행 추적 컬럼 추가
-- phpMyAdmin에서 실행 또는: mysql -u root marketo_automation < sql/migrations/bulk_import.sql

USE `marketo_automation`;

ALTER TABLE `campaigns`
  ADD COLUMN `bulk_job_id`     VARCHAR(100) DEFAULT NULL                                          AFTER `lead_count`,
  ADD COLUMN `bulk_status`     VARCHAR(20)  DEFAULT NULL COMMENT 'Importing|Complete|Failed'      AFTER `bulk_job_id`,
  ADD COLUMN `bulk_started_at` DATETIME     DEFAULT NULL                                          AFTER `bulk_status`;
