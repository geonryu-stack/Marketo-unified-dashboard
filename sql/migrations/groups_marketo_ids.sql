-- sql/migrations/groups_marketo_ids.sql
-- 발송 그룹 프리셋: 세그먼트 생성 시 Marketo Program/Email Program ID 1클릭 자동 채움.
-- 메모리의 [발송 그룹 IDs] 표 기반으로 program_id를 시드한다.
-- email_program_id는 운영자가 첫 발송 시 직접 입력 후 UPDATE.

USE `marketo_automation`;

ALTER TABLE `groups`
  ADD COLUMN `marketo_program_id`       INT DEFAULT NULL AFTER `name`,
  ADD COLUMN `marketo_email_program_id` INT DEFAULT NULL AFTER `marketo_list_id`;

UPDATE `groups` SET `marketo_program_id` = 7309 WHERE `id` = 'active-a';
UPDATE `groups` SET `marketo_program_id` = 7310 WHERE `id` = 'active-b';
UPDATE `groups` SET `marketo_program_id` = 7312 WHERE `id` = 'fp-active';
UPDATE `groups` SET `marketo_program_id` = 7311 WHERE `id` = 'np-active';
