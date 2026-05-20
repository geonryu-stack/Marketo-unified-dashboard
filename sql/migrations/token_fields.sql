-- migration: campaigns 테이블에 이메일 컨텐츠 토큰 컬럼 추가 + send_time 날짜+시간 지원
ALTER TABLE `campaigns`
  ADD COLUMN `email_title`     VARCHAR(500) DEFAULT NULL AFTER `emoji`,
  ADD COLUMN `email_preheader` VARCHAR(500) DEFAULT NULL AFTER `email_title`;

ALTER TABLE `campaigns`
  MODIFY COLUMN `send_time` VARCHAR(50) NOT NULL DEFAULT '';
