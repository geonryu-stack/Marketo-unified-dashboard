-- sql/migrations/screenshot.sql
-- Sprint 1 ASSET — 결재 카드 테스트 메일 스크린샷 첨부 — 2026-05-20
-- 변경 사항:
--  1) campaigns 테이블에 test_screenshot_path 컬럼 추가
--     운영자가 awaiting_approval 단계에서 첨부한 테스트 메일 스크린샷 경로 (data/screenshots/...)

USE `marketo_automation`;

ALTER TABLE `campaigns`
  ADD COLUMN `test_screenshot_path` VARCHAR(255) DEFAULT NULL
    COMMENT 'awaiting_approval 단계에서 운영자가 첨부한 테스트 메일 스크린샷 경로'
    AFTER `reject_memo`;
