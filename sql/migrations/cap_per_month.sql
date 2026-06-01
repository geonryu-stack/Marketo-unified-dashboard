-- cap_per_month.sql — IMPROVEMENT_SPEC #4
-- 월간 frequency cap 컬럼 추가.
-- 기존 세그먼트의 cap_per_day/cap_per_week 값은 변경하지 않음 (운영자 설정값 보존).
-- 신규 세그먼트는 공격적 기본값 (3/5/15) 적용 — schema.sql 참조.

ALTER TABLE segments
  ADD COLUMN `cap_per_month` INT NOT NULL DEFAULT 15
  COMMENT '동일 수신자에게 30일 내 허용되는 최대 발송 수 (0 = 무제한)'
  AFTER `cap_per_week`;
