-- sql/migrations/status_history.sql
-- Sprint 1 INFRA — 상태 전이 감사 추적 테이블 (2026-05-20)
-- 모든 campaigns.status 변경을 단일 append-only 로그로 적재해
--   1) needs_manual_review/failed 전환 알림(㉑ Slack)의 트리거 근거
--   2) S2 KPI 대시보드(⑲)의 결재 시간/사이클타임 계산
--   3) 사고시 timeline 재구성
-- 위 3 용도를 지원한다. 본 마이그레이션은 테이블 신설만 다룬다.

USE `marketo_automation`;

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
