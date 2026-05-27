-- sql/schema.sql
-- 신규 설치 시 이 한 파일로 최신 상태 완성. 기존 환경은 sql/migrations/*.sql 순서대로 적용.
-- 최종 업데이트: 2026-05-21 — 모든 migration 통합 (approval, bulk_import, delivery_tracking,
--                                token_fields, defaults, segment_id_index, run_id, status_history, screenshot,
--                                content_presets, vvip_suppression, activity_next_token, marketo_api_calls,
--                                campaign_engagement, lead_send_cap)
CREATE DATABASE IF NOT EXISTS `marketo_automation`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `marketo_automation`;

CREATE TABLE IF NOT EXISTS `segments` (
  `id` VARCHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `filters` TEXT NOT NULL,
  `suppresses_segment_ids` TEXT NOT NULL DEFAULT ('[]') COMMENT 'VVIP 등 우선순위 높은 세그먼트가 같은 날 다른 세그먼트 모수에서 자신을 제외시키기 위한 링크. JSON array of segment IDs.',
  `last_count` INT DEFAULT NULL,
  `last_extracted_at` VARCHAR(50) DEFAULT NULL,
  `marketo_program_id` VARCHAR(100) NOT NULL DEFAULT '',
  `marketo_audience_list_id` VARCHAR(100) NOT NULL DEFAULT '',
  `marketo_email_program_id` VARCHAR(100) NOT NULL DEFAULT '',
  `is_recurring` TINYINT(1) NOT NULL DEFAULT 0,
  `cap_per_day` INT NOT NULL DEFAULT 1 COMMENT '동일 수신자에게 같은 날 허용되는 최대 발송 수 (0 = 무제한)',
  `cap_per_week` INT NOT NULL DEFAULT 7 COMMENT '동일 수신자에게 7일 내 허용되는 최대 발송 수 (0 = 무제한)',
  `cap_priority` INT NOT NULL DEFAULT 100 COMMENT '큰 수가 우선. 본 추출 시 priority>=self 인 다른 세그먼트 hold/sent 만 cap 카운트에 포함',
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
  `open_count` INT NOT NULL DEFAULT 0,
  `click_count` INT NOT NULL DEFAULT 0,
  `unsubscribe_count` INT NOT NULL DEFAULT 0,
  `last_activity_at` VARCHAR(50) DEFAULT NULL COMMENT '발송 트리클 종료 판단용',
  `poll_status` VARCHAR(20) NOT NULL DEFAULT 'idle' COMMENT 'idle|polling|done|timeout',
  `poll_started_at` DATETIME DEFAULT NULL,
  `poll_next_at` DATETIME DEFAULT NULL,
  `activity_polled_at` DATETIME DEFAULT NULL,
  `activity_next_token` TEXT DEFAULT NULL COMMENT 'Activity 폴링 maxPages 도달 시 이어받기 토큰',
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
  `marketo_program_id` INT DEFAULT NULL,
  `marketo_campaign_id` INT NOT NULL,
  `marketo_list_id` INT NOT NULL,
  `marketo_email_program_id` INT DEFAULT NULL,
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

INSERT IGNORE INTO `groups` (`id`, `name`, `marketo_program_id`, `marketo_campaign_id`, `marketo_list_id`, `sort_order`) VALUES
('active-a',  'Active A',  7309, 7610, 8293, 0),
('active-b',  'Active B',  7310, 7611, 8294, 1),
('fp-active', 'FP Active', 7312, 7613, 8296, 2),
('np-active', 'NP Active', 7311, 7612, 8295, 3);
-- marketo_email_program_id는 운영자가 첫 사용 시 UPDATE

-- VVIP 우선순위 Suppression — suppressor 캠페인이 추출 시점에 흡수한 이메일을 박제.
-- Active 추출 시 같은 send_date 활성 suppressor 캠페인의 이메일을 NOT IN으로 제외.
CREATE TABLE IF NOT EXISTS `segment_lead_suppressions` (
  `suppressor_segment_id`  VARCHAR(36) NOT NULL,
  `suppressor_campaign_id` VARCHAR(36) NOT NULL,
  `send_date`              VARCHAR(10) NOT NULL COMMENT 'YYYY-MM-DD (campaigns.send_time 의 날짜부)',
  `email`                  VARCHAR(320) NOT NULL,
  `created_at`             VARCHAR(50) NOT NULL,
  PRIMARY KEY (`suppressor_campaign_id`, `email`),
  KEY `idx_send_date_email`  (`send_date`, `email`),
  KEY `idx_suppressor_seg`   (`suppressor_segment_id`, `send_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 발송 결과 시계열 — 회차 비교·시간 추이 분석용 (L2 계층).
-- L1 집계는 campaigns 테이블에 누적. raw events(L3) 는 MVP 보류.
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

-- Marketo API 일별·endpoint별 콜 카운터 (PR-4 δ).
-- 데이터 기반 운영 의사결정용 — 50K/일 한도 대비 실제 분포 측정.
CREATE TABLE IF NOT EXISTS `marketo_api_calls` (
  `date`         VARCHAR(10) NOT NULL COMMENT 'YYYY-MM-DD (KST)',
  `endpoint`     VARCHAR(100) NOT NULL,
  `count`        INT NOT NULL DEFAULT 0,
  `error_count`  INT NOT NULL DEFAULT 0,
  `last_updated` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`date`, `endpoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 리드(이메일 주소)별 일/주 cap — append-only 발송 히스토리. cap 계산의 source-of-truth.
-- 추출 직후 state='hold' 박제 → Marketo Activity API 가 sent 가져오면 state='sent' confirm.
-- 캠페인 cancel/fail 시 hold rows DELETE. 30일 초과 row 는 cron/cleanup_lead_send_history.php 가 정리.
CREATE TABLE IF NOT EXISTS `lead_send_history` (
  `email`        VARCHAR(320) NOT NULL,
  `send_date`    DATE         NOT NULL COMMENT 'campaigns.send_time 의 날짜부 (YYYY-MM-DD)',
  `campaign_id`  VARCHAR(36)  NOT NULL,
  `segment_id`   VARCHAR(36)  NOT NULL,
  `lead_id`      INT          NULL COMMENT 'Marketo lead id — upsert 직후 매칭. NULL 이면 Activity confirm 시 email 만 사용.',
  `priority`     INT          NOT NULL DEFAULT 0 COMMENT '추출 시점의 cap_priority 박제 (세그먼트 priority 사후 변경 영향 차단)',
  `state`        ENUM('hold', 'sent') NOT NULL DEFAULT 'hold',
  `created_at`   VARCHAR(50)  NOT NULL,
  `confirmed_at` VARCHAR(50)  NULL,
  PRIMARY KEY (`email`, `send_date`, `campaign_id`),
  KEY `idx_email_date`      (`email`, `send_date`),
  KEY `idx_send_date_state` (`send_date`, `state`),
  KEY `idx_campaign_id`     (`campaign_id`),
  KEY `idx_campaign_leadid` (`campaign_id`, `lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sprint 2 ASSET — 콘텐츠 프리셋 저장 (v1은 JS 상수, v2에서 endpoint 도입 예정)
CREATE TABLE IF NOT EXISTS `content_presets` (
  `id` VARCHAR(36) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `emoji` VARCHAR(20) DEFAULT NULL,
  `title_template` VARCHAR(500) DEFAULT NULL,
  `preheader_template` VARCHAR(500) DEFAULT NULL,
  `created_at` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
