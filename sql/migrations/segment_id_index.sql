-- migration: campaigns.segment_id 인덱스 추가
-- 목적: WHERE segment_id=? ORDER BY id FOR UPDATE 쿼리가 풀 테이블 스캔 대신
--       인덱스 레인지 스캔을 사용하도록 강제, FOR UPDATE 잠금이 해당 세그먼트 행만 잡도록 보장.
ALTER TABLE `campaigns`
  ADD KEY `idx_segment_id` (`segment_id`);
