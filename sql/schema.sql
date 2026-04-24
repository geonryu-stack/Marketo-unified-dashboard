-- sql/schema.sql
CREATE DATABASE IF NOT EXISTS `marketo_automation`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `marketo_automation`;

CREATE TABLE IF NOT EXISTS `segments` (
  `id` VARCHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT '',
  `filters` TEXT NOT NULL DEFAULT '[]',
  `last_count` INT DEFAULT NULL,
  `last_extracted_at` VARCHAR(50) DEFAULT NULL,
  `marketo_program_id` VARCHAR(100) NOT NULL DEFAULT '',
  `marketo_audience_list_id` VARCHAR(100) NOT NULL DEFAULT '',
  `marketo_email_program_id` VARCHAR(100) NOT NULL DEFAULT '',
  `is_recurring` TINYINT(1) NOT NULL DEFAULT 0,
  `send_day_of_week` INT NOT NULL DEFAULT 1,
  `recurring_send_time` VARCHAR(10) NOT NULL DEFAULT '10:00',
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
  `reward_url` TEXT NOT NULL DEFAULT '',
  `scheduled_at` VARCHAR(50) NOT NULL,
  `send_time` VARCHAR(10) NOT NULL DEFAULT '',
  `marketo_list_id` VARCHAR(100) DEFAULT NULL,
  `marketo_list_name` VARCHAR(255) DEFAULT NULL,
  `marketo_cloned_email_id` VARCHAR(100) DEFAULT NULL,
  `marketo_email_program_id` VARCHAR(100) DEFAULT NULL,
  `marketo_campaign_id` VARCHAR(100) DEFAULT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'draft',
  `lead_count` INT NOT NULL DEFAULT 0,
  `error_message` TEXT DEFAULT NULL,
  `created_at` VARCHAR(50) NOT NULL,
  `updated_at` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `job_logs` (
  `id` VARCHAR(36) NOT NULL,
  `campaign_id` VARCHAR(36) NOT NULL,
  `step` VARCHAR(100) NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
  `message` TEXT DEFAULT NULL,
  `created_at` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_id` (`campaign_id`)
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
