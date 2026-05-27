-- sql/migrations/lead_send_cap.sql
-- 리드(이메일 주소)별 일/주 단위 발송 cap 도입 (2026-05-21)
--
-- 변경 사항:
--  1) segments.cap_per_day / cap_per_week / cap_priority — 세그먼트별 cap 정책 설정
--  2) lead_send_history — 이메일 × 발송일자별 hold/sent 박제 (append-only). cap 계산의 source-of-truth.
--
-- 사내 DB 는 SELECT only (CONSTRAINT-01) — 본 변경은 앱 DB(marketo_automation)만 영향.

USE `marketo_automation`;

ALTER TABLE `segments`
  ADD COLUMN `cap_per_day`  INT NOT NULL DEFAULT 1
    COMMENT '동일 수신자에게 같은 날 허용되는 최대 발송 수 (0 = 무제한)' AFTER `is_recurring`,
  ADD COLUMN `cap_per_week` INT NOT NULL DEFAULT 7
    COMMENT '동일 수신자에게 7일 내 허용되는 최대 발송 수 (0 = 무제한)' AFTER `cap_per_day`,
  ADD COLUMN `cap_priority` INT NOT NULL DEFAULT 100
    COMMENT '큰 수가 우선. 본 세그먼트 추출 시 priority 가 같거나 큰 hold/sent 만 cap 카운트에 반영' AFTER `cap_per_week`;

CREATE TABLE IF NOT EXISTS `lead_send_history` (
  `email`        VARCHAR(320) NOT NULL,
  `send_date`    DATE         NOT NULL COMMENT 'campaigns.send_time 의 날짜부 (YYYY-MM-DD)',
  `campaign_id`  VARCHAR(36)  NOT NULL,
  `segment_id`   VARCHAR(36)  NOT NULL,
  `lead_id`      INT          NULL COMMENT 'Marketo lead id. upsert 직후 매칭, NULL 이면 Activity confirm 시 email 만으로 매칭 시도',
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
