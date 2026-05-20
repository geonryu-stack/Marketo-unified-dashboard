-- sql/migrations/approval.sql
-- 캠페인 결재 워크플로 도입 — 2026-05-12
-- 변경 사항:
--  1) 감사 컬럼: approved_at / rejected_at / reject_memo
--  2) 기존 'test_sent' 상태를 'awaiting_approval'로 마이그레이션

USE `marketo_automation`;

ALTER TABLE `campaigns`
  ADD COLUMN `approved_at` VARCHAR(50) DEFAULT NULL AFTER `updated_at`,
  ADD COLUMN `rejected_at` VARCHAR(50) DEFAULT NULL AFTER `approved_at`,
  ADD COLUMN `reject_memo` TEXT DEFAULT NULL AFTER `rejected_at`;

UPDATE `campaigns`
   SET `status` = 'awaiting_approval'
 WHERE `status` = 'test_sent';
