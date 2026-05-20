-- sql/migrations/run_id.sql
-- Sprint 0 INFRA — 발송 1회를 추적하기 위한 run_id 도입 (2026-05-20)
-- 변경 사항:
--  1) campaigns.run_id: 캠페인 단위 추적 UUID (현재 발송 사이클 식별)
--  2) job_logs.run_id : 동일 발송 사이클에 속한 로그를 묶기 위한 추적 UUID + 인덱스
-- run_id는 ScheduleRunner가 새 사이클 진입 시 발급, 본 sprint에서는 컬럼 + helper 만 준비.

USE `marketo_automation`;

ALTER TABLE `campaigns`
  ADD COLUMN `run_id` VARCHAR(36) DEFAULT NULL COMMENT '발송 1회 추적 UUID' AFTER `reject_memo`;

ALTER TABLE `job_logs`
  ADD COLUMN `run_id` VARCHAR(36) DEFAULT NULL COMMENT '발송 1회 추적 UUID' AFTER `status`,
  ADD KEY `idx_run_id` (`run_id`);
