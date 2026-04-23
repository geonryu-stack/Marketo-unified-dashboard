// ============================================================
// 공통 타입 정의
// ============================================================

// --- 세그먼트 ---

export type FilterOperator =
  | '='
  | '!='
  | '>'
  | '>='
  | '<'
  | '<='
  | 'IN'
  | 'NOT IN'
  | 'LIKE'
  | 'IS NULL'
  | 'IS NOT NULL';

export interface FilterCondition {
  field: string;       // DB 컬럼명
  label: string;       // UI 표시명
  operator: FilterOperator;
  value: string;       // 단일 값 또는 IN절에서는 콤마 구분 문자열
}

export interface Segment {
  id: string;
  name: string;
  description: string;
  filters: FilterCondition[];
  last_count: number | null;         // 마지막 추출 시 대상자 수
  last_extracted_at: string | null;
  marketo_program_id: string;        // Marketo Email Program ID (발송 + 폴더 정리용)
  marketo_audience_list_id: string;  // Email Program Audience의 고정 Static List ID
  is_recurring: number;              // SQLite INTEGER 0|1
  send_day_of_week: number;          // 0=일 ~ 6=토
  recurring_send_time: string;       // 'HH:MM' e.g. '10:00'
  created_at: string;
  updated_at: string;
}

// --- 에셋 라이브러리 ---

export interface AssetLibraryItem {
  id: string;
  name: string;                         // 에셋 식별명 (내부 관리용)
  image_url: string;                    // 이메일 메인 이미지 URL
  subject: string;                      // 이메일 제목 (이모지 없이)
  emoji: string;                        // 제목 앞 이모지
  preheader: string;                    // 프리헤더 텍스트
  body_text: string;                    // 이메일 본문 요약 텍스트
  tags: string;                         // 콤마 구분 태그 문자열
  marketo_email_id: string | null;      // Clone 기준이 되는 Marketo 이메일 에셋 ID
  marketo_program_id: string | null;    // Clone 대상 Marketo 프로그램 ID
  marketo_folder_id: number | null;     // Clone 저장 폴더 ID
  reward_url_placeholder: string;       // 치환할 플레이스홀더 (기본: {{REWARD_URL}})
  // Token 발송 모드 — Clone 없이 My Token으로 콘텐츠 주입
  send_mode: 'clone' | 'token';
  marketo_token_image: string;          // e.g. {{my.imageUrl}}
  marketo_token_subject: string;        // e.g. {{my.subjectLine}}
  marketo_token_preheader: string;      // e.g. {{my.preheader}}
  marketo_token_body: string;           // e.g. {{my.bodyText}}
  marketo_token_emoji: string;          // e.g. {{my.emoji}}
  marketo_token_reward_url: string;     // e.g. {{my.rewardUrl}}
  created_at: string;
  updated_at: string;
}

// --- 캠페인 ---

export type CampaignStatus =
  | 'draft'               // 초안
  | 'confirmed'           // 사용자 확인 완료, 자동 실행 대기
  | 'extracting'          // DB 추출 중
  | 'uploading'           // Marketo 리드 업로드 중
  | 'preparing'           // Email Program Token 설정 + 테스트 메일 발송 중
  | 'awaiting_approval'   // 테스트 메일 발송 완료, 담당자 승인 대기
  | 'scheduling'          // Email Program 예약 중 (Phase 2)
  | 'scheduled'           // 예약 완료
  | 'cancelling'          // 예약 취소 중 — Marketo unapprove 진행 중 (이 상태에서 Phase 1 실행 불가)
  | 'sent'                // 발송 완료
  | 'failed';             // 실패

export interface Campaign {
  id: string;
  name: string;
  segment_id: string;
  segment_name: string;
  asset_library_id: string;
  asset_name: string;
  reward_url: string;
  scheduled_at: string;           // ISO 8601 — Phase 1 자동 실행 시각 (Cron 기준)
  send_time: string;              // RTZ 발송 시각 (예: "10:00") — Phase 2에서 Email Program에 설정

  // Marketo 연결 정보
  marketo_list_id: string | null;       // 세그먼트 고정 Static List ID (audience)
  marketo_list_name: string | null;
  marketo_cloned_email_id: string | null;
  marketo_campaign_id: string | null;   // Smart Campaign fallback 예약 시 SC ID 저장 — cancel 경로에서 분기 판단에 사용

  status: CampaignStatus;
  lead_count: number;
  error_message: string | null;
  created_at: string;
  updated_at: string;
}

// --- Job 로그 ---

export type JobLogStatus = 'pending' | 'running' | 'done' | 'error';

export interface JobLog {
  id: string;
  campaign_id: string;
  step: string;
  status: JobLogStatus;
  message: string | null;
  created_at: string;
}

// --- Marketo API 응답 ---

export interface MarketoTokenResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
  scope: string;
}

export interface MarketoLead {
  id?: number;
  email: string;
  firstName?: string;
  lastName?: string;
  [key: string]: unknown;
}

export interface MarketoList {
  id: number;
  name: string;
  programName?: string;
  createdAt: string;
  updatedAt: string;
}

export interface MarketoEmailAsset {
  id: number;
  name: string;
  subject?: string;
  preHeader?: string;
  status?: string;
  folder?: { id: number; type: string };
  createdAt: string;
  updatedAt: string;
}

export interface MarketoCampaign {
  id: number;
  name: string;
  status: string;
  type: string;
  programId?: number;
  programName?: string;
  createdAt: string;
  updatedAt: string;
}

// --- 내부 DB 필터 필드 정의 ---

export type FieldType = 'text' | 'number' | 'date' | 'select' | 'boolean';

export interface FilterFieldDef {
  field: string;         // DB 컬럼명 또는 SQL 표현식
  label: string;         // UI 표시명
  type: FieldType;
  sql_expr?: string;     // 필드가 표현식인 경우 (ex: DATEDIFF(NOW(), last_login_at))
  options?: string[];    // type='select'일 때 선택지
}

// --- API 응답 공통 ---

export interface ApiResponse<T> {
  success: boolean;
  data?: T;
  error?: string;
}

// --- 반복 발송 그룹 대시보드 ---

export interface RecurringSegmentRow {
  id: string;
  name: string;
  send_day_of_week: number;
  recurring_send_time: string;
  latest_campaign_id: string | null;
  latest_campaign_name: string | null;
  latest_campaign_status: string | null;
}

// --- 새 발송 대시보드 ---

export interface SendGroup {
  id: string;
  name: string;
  marketo_campaign_id: number;
  marketo_list_id: number;
  sort_order: number;
}

export interface DaySend {
  id: string;
  group_id: string;
  send_date: string;           // 'YYYY-MM-DD'
  marketo_email_id: number;
  marketo_email_name: string;
  send_time: string;           // 'HH:MM'
  timezone: 'RTZ' | 'KST';
  status: 'draft' | 'test_sent' | 'scheduled' | 'sent' | 'failed';
  test_sent_at: string | null;
  scheduled_at: string | null;
  error_message: string | null;
  created_at: string;
  updated_at: string;
}

export interface MarketoEmailItem {
  id: number;
  name: string;
  status: string;
  updatedAt: string;
}
