-- sql/schema.sql
-- 신규 설치 시 이 한 파일로 최신 상태 완성. 기존 환경은 sql/migrations/*.sql 순서대로 적용.
-- 최종 업데이트: 2026-05-20 — 모든 migration 통합 (approval, bulk_import, delivery_tracking,
--                                token_fields, defaults, segment_id_index, run_id, status_history, screenshot)
CREATE DATABASE IF NOT EXISTS `marketo_automation`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `marketo_automation`;

CREATE TABLE IF NOT EXISTS `segments` (
  `id` VARCHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `filters` TEXT NOT NULL,
  `last_count` INT DEFAULT NULL,
  `last_extracted_at` VARCHAR(50) DEFAULT NULL,
  `marketo_program_id` VARCHAR(100) NOT NULL DEFAULT '',
  `marketo_audience_list_id` VARCHAR(100) NOT NULL DEFAULT '',
  `marketo_email_program_id` VARCHAR(100) NOT NULL DEFAULT '',
  `is_recurring` TINYINT(1) NOT NULL DEFAULT 0,
  `send_day_of_week` INT NOT NULL DEFAULT 1,
  `recurring_send_time` VARCHAR(10) NOT NULL DEFAULT '10:00',
  `default_email_id` VARCHAR(100) NOT NULL DEFAULT '',
  `default_asset_name` VARCHAR(255) NOT NULL DEFAULT '',
  `default_reward_url` TEXT,
  `default_emoji` VARCHAR(20) DEFAULT NULL,
  `default_send_time` VARCHAR(10) NOT NULL DEFAULT '10:00',
  `default_name_prefix` VARCHAR(100) NOT NULL DEFAULT '',
  `created_at` VARCHAR(50) NOT NULL,
  `updated_at` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `campaigns` (
  `id` VARCHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `segment_id` VARCHAR(36) NOT NULL,
  `segment_name` VARCHAR(255) NOT NULL,
  `asset_name` VARCHAR(255) NOT NULL DEFAULT '',
  `reward_url` TEXT NOT NULL,
  `scheduled_at` VARCHAR(50) NOT NULL,
  `send_time` VARCHAR(50) NOT NULL DEFAULT '',
  `marketo_list_id` VARCHAR(100) DEFAULT NULL,
  `marketo_list_name` VARCHAR(255) DEFAULT NULL,
  `marketo_cloned_email_id` VARCHAR(100) DEFAULT NULL,
  `marketo_email_program_id` VARCHAR(100) DEFAULT NULL,
  `marketo_campaign_id` VARCHAR(100) DEFAULT NULL,
  `emoji` VARCHAR(20) DEFAULT NULL,
  `email_title` VARCHAR(500) DEFAULT NULL,
  `email_preheader` VARCHAR(500) DEFAULT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'draft',
  `lead_count` INT NOT NULL DEFAULT 0,
  `bulk_job_id` VARCHAR(100) DEFAULT NULL,
  `bulk_status` VARCHAR(20) DEFAULT NULL COMMENT 'Importing|Complete|Failed',
  `bulk_started_at` DATETIME DEFAULT NULL,
  `sent_count` INT NOT NULL DEFAULT 0,
  `delivered_count` INT NOT NULL DEFAULT 0,
  `bounce_count` INT NOT NULL DEFAULT 0,
  `poll_status` VARCHAR(20) NOT NULL DEFAULT 'idle' COMMENT 'idle|polling|done|timeout',
  `poll_started_at` DATETIME DEFAULT NULL,
  `poll_next_at` DATETIME DEFAULT NULL,
  `activity_polled_at` DATETIME DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at` VARCHAR(50) NOT NULL,
  `updated_at` VARCHAR(50) NOT NULL,
  `approved_at` VARCHAR(50) DEFAULT NULL,
  `rejected_at` VARCHAR(50) DEFAULT NULL,
  `reject_memo` TEXT DEFAULT NULL,
  `test_screenshot_path` VARCHAR(255) DEFAULT NULL COMMENT 'awaiting_approval 단계에서 운영자가 첨부한 테스트 메일 스크린샷 경로',
  `run_id` VARCHAR(36) DEFAULT NULL COMMENT '발송 1회 추적 UUID',
  PRIMARY KEY (`id`),
  KEY `idx_segment_id` (`segment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `job_logs` (
  `id` VARCHAR(36) NOT NULL,
  `campaign_id` VARCHAR(36) NOT NULL,
  `step` VARCHAR(100) NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
  `run_id` VARCHAR(36) DEFAULT NULL COMMENT '발송 1회 추적 UUID',
  `message` TEXT DEFAULT NULL,
  `created_at` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_id` (`campaign_id`),
  KEY `idx_run_id` (`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `status_history` (
  `id` VARCHAR(36) NOT NULL,
  `campaign_id` VARCHAR(36) NOT NULL,
  `from_status` VARCHAR(50) DEFAULT NULL,
  `to_status` VARCHAR(50) NOT NULL,
  `actor` VARCHAR(50) NOT NULL DEFAULT 'system' COMMENT 'cron|user|system',
  `notes` TEXT DEFAULT NULL,
  `run_id` VARCHAR(36) DEFAULT NULL,
  `created_at` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_id` (`campaign_id`),
  KEY `idx_run_id` (`run_id`),
  KEY `idx_to_status_created` (`to_status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `groups` (
  `id` VARCHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `marketo_campaign_id` INT NOT NULL,
  `marketo_list_id` INT NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `send_schedules` (
  `id` VARCHAR(36) NOT NULL,
  `group_id` VARCHAR(36) NOT NULL,
  `send_date` VARCHAR(20) NOT NULL,
  `marketo_email_id` INT NOT NULL,
  `marketo_email_name` VARCHAR(255) NOT NULL DEFAULT '',
  `send_time` VARCHAR(10) NOT NULL DEFAULT '10:00',
  `timezone` VARCHAR(10) NOT NULL DEFAULT 'RTZ',
  `status` VARCHAR(50) NOT NULL DEFAULT 'draft',
  `test_sent_at` VARCHAR(50) DEFAULT NULL,
  `scheduled_at` VARCHAR(50) DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at` VARCHAR(50) NOT NULL,
  `updated_at` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_group_date` (`group_id`, `send_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `groups` (`id`, `name`, `marketo_campaign_id`, `marketo_list_id`, `sort_order`) VALUES
('active-a',  'Active A',  7610, 8293, 0),
('active-b',  'Active B',  7611, 8294, 1),
('fp-active', 'FP Active', 7613, 8296, 2),
('np-active', 'NP Active', 7612, 8295, 3);
