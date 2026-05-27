-- sql/migrations/vvip_suppression.sql
-- VVIP 우선순위 Suppression 도입 (2026-05-21)
--
-- 변경 사항:
--  1) segments.suppresses_segment_ids: 본 세그먼트 발송 시 같은 날 발송 예정인 어떤 세그먼트의
--     모수에서 자신을 제외할지 JSON 배열로 저장. 빈 '[]' = suppressor 아님.
--  2) segment_lead_suppressions: suppressor(VVIP 등) 추출 시점에 그 캠페인이 흡수한 이메일 목록을
--     앱 DB에 박제. Active 추출 시 NOT IN 조건의 단일 진실(source of truth).
--
-- 사내 DB는 SELECT only (CONSTRAINT-01) — 본 변경은 앱 DB(marketo_automation)만 영향.

USE `marketo_automation`;

ALTER TABLE `segments`
  ADD COLUMN `suppresses_segment_ids` TEXT NOT NULL DEFAULT ('[]') AFTER `filters`;

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
