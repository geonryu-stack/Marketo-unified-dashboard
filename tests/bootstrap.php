<?php
declare(strict_types=1);

// helpers.php contains all pure functions under test:
// build_campaign_tokens(), parse_send_time(), etc.
require_once __DIR__ . '/../src/helpers.php';
// Sprint 1 INFRA — Notifier 클래스(슬랙 webhook + stdout 폴백).
require_once __DIR__ . '/../src/Notifier.php';
// VVIP 우선순위 Suppression 도메인 클래스 — 순수 헬퍼는 DB 없이 단위 테스트 가능.
require_once __DIR__ . '/../src/Suppression.php';
// InternalDB — injectQueryTimeoutHint 순수함수 단위 테스트용 (실제 PDO 연결 없이 정적 호출).
require_once __DIR__ . '/../src/InternalDB.php';
// 리드별 cap — 순수 헬퍼(extractSentTargets) 단위 테스트용.
require_once __DIR__ . '/../src/SendCap.php';
