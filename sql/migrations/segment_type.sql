-- segment_type.sql — IMPROVEMENT_SPEC #1 Option A
-- 세그먼트 유형 컬럼 추가. 기존 세그먼트는 전부 'active' 기본값.
-- 허용값: active, reengagement, transactional, lifecycle, custom (PHP에서 검증)

ALTER TABLE segments
  ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'active'
  AFTER `description`;
