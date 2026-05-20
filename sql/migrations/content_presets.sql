-- sql/migrations/content_presets.sql
-- Sprint 2 ASSET — 콘텐츠 프리셋 저장 테이블.
-- v1은 assets/js/campaign.js의 CONTENT_PRESETS 상수로 동작하고,
-- v2에서 endpoint(api/content-presets.php)가 도입되면 이 테이블을 백엔드 저장소로 사용한다.
-- INFRA zone(index.php 라우터) 변경이 다음 sprint에 수반되므로, 본 sprint에서는 테이블만 선반영.
USE `marketo_automation`;

CREATE TABLE IF NOT EXISTS `content_presets` (
  `id` VARCHAR(36) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `emoji` VARCHAR(20) DEFAULT NULL,
  `title_template` VARCHAR(500) DEFAULT NULL,
  `preheader_template` VARCHAR(500) DEFAULT NULL,
  `created_at` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
