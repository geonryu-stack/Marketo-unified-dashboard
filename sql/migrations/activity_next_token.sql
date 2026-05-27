-- sql/migrations/activity_next_token.sql
-- Fix 2 (PR-2) — Activity 폴링 maxPages 캡 + 이어받기 (2026-05-21)
--
-- 변경 사항:
--  1) campaigns.activity_next_token: Activity API 폴링이 한 cron 주기 내에서
--     maxPages 캡에 도달했을 때 마지막 nextPageToken 을 박제. 다음 cron 이
--     이 토큰부터 이어받아 데이터 누락 없이 분할 폴링.
--
-- 정상 완료 시 NULL 로 reset. status='timeout'/'done' 으로 끝나도 다음 사이클을 위해 비움.

USE `marketo_automation`;

ALTER TABLE `campaigns`
  ADD COLUMN `activity_next_token` TEXT DEFAULT NULL
  COMMENT 'Activity 폴링 maxPages 도달 시 이어받기 토큰. 정상 완료/timeout 시 NULL.'
  AFTER `activity_polled_at`;
