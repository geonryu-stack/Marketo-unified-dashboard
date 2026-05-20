-- migration: segments 테이블에 기본 발송 설정 컬럼 추가
ALTER TABLE `segments`
  ADD COLUMN `default_email_id`    VARCHAR(100) NOT NULL DEFAULT '' AFTER `recurring_send_time`,
  ADD COLUMN `default_asset_name`  VARCHAR(255) NOT NULL DEFAULT '' AFTER `default_email_id`,
  ADD COLUMN `default_reward_url`  TEXT                             AFTER `default_asset_name`,
  ADD COLUMN `default_emoji`       VARCHAR(20)  DEFAULT NULL        AFTER `default_reward_url`,
  ADD COLUMN `default_send_time`   VARCHAR(10)  NOT NULL DEFAULT '10:00' AFTER `default_emoji`,
  ADD COLUMN `default_name_prefix` VARCHAR(100) NOT NULL DEFAULT '' AFTER `default_send_time`;
