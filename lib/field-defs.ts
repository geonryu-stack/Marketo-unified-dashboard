/**
 * 사내 DB 세그먼트 빌더용 필드 정의
 * 실제 DB 스키마에 맞게 수정하세요.
 * - field: DB 컬럼명 (또는 SQL 표현식의 alias)
 * - label: UI 표시명
 * - type: 필드 유형
 * - sql_expr: 표현식 필드일 경우 실제 SQL 식
 * - options: type='select'일 때 선택지
 */

import { FilterFieldDef } from './types';

export const FIELD_DEFS: FilterFieldDef[] = [
  // ── 기본 사용자 정보 ──────────────────────────────
  {
    field: 'email',
    label: '이메일',
    type: 'text',
  },
  {
    field: 'user_id',
    label: '사용자 ID',
    type: 'text',
  },
  {
    field: 'country',
    label: '국가',
    type: 'select',
    options: ['KR', 'US', 'JP', 'TW', 'TH', 'VN', 'PH'],
  },
  {
    field: 'platform',
    label: '플랫폼',
    type: 'select',
    options: ['ios', 'android'],
  },
  {
    field: 'language',
    label: '언어',
    type: 'select',
    options: ['ko', 'en', 'ja', 'zh', 'th', 'vi'],
  },

  // ── 활동 지표 ─────────────────────────────────────
  {
    field: 'days_since_login',
    label: '마지막 로그인 경과일 (일)',
    type: 'number',
    sql_expr: 'DATEDIFF(NOW(), last_login_at)',
  },
  {
    field: 'days_since_register',
    label: '가입 후 경과일 (일)',
    type: 'number',
    sql_expr: 'DATEDIFF(NOW(), created_at)',
  },

  // ── 구매/결제 ─────────────────────────────────────
  {
    field: 'total_purchase_count',
    label: '총 결제 횟수',
    type: 'number',
  },
  {
    field: 'total_purchase_amount',
    label: '총 결제 금액',
    type: 'number',
  },
  {
    field: 'days_since_purchase',
    label: '마지막 결제 경과일 (일)',
    type: 'number',
    sql_expr: 'DATEDIFF(NOW(), last_purchase_at)',
  },

  // ── 상태 ──────────────────────────────────────────
  {
    field: 'is_active',
    label: '활성 상태',
    type: 'boolean',
  },
  {
    field: 'user_level',
    label: '사용자 레벨',
    type: 'number',
  },
  {
    field: 'marketing_consent',
    label: '마케팅 수신 동의',
    type: 'boolean',
  },
];
