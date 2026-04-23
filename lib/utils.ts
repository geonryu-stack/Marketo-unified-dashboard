import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';
import { FilterCondition, FilterFieldDef } from './types';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

// ────────────────────────────────────────────
// SQL 빌더 (세그먼트 필터 → WHERE 절 변환)
// ────────────────────────────────────────────

/**
 * FilterCondition 배열을 SQL WHERE 절 문자열로 변환합니다.
 * SQL Injection 방지를 위해 파라미터 바인딩 배열도 함께 반환합니다.
 *
 * @returns { sql: string, params: unknown[] }
 */
export function buildWhereClause(
  filters: FilterCondition[],
  fieldDefs: FilterFieldDef[]
): { sql: string; params: unknown[] } {
  if (filters.length === 0) return { sql: '1=1', params: [] };

  const defMap = new Map(fieldDefs.map((d) => [d.field, d]));
  const clauses: string[] = [];
  const params: unknown[] = [];

  for (const f of filters) {
    const def = defMap.get(f.field);
    if (!def) {
      throw new Error(
        `알 수 없는 필터 필드: '${f.field}'. 세그먼트 편집 화면에서 해당 조건을 제거하거나 유효한 필드로 교체하세요.`
      );
    }

    const col = def.sql_expr ?? `\`${def.field}\``;

    switch (f.operator) {
      case '=':
      case '!=':
      case '>':
      case '>=':
      case '<':
      case '<=':
        clauses.push(`${col} ${f.operator} ?`);
        params.push(castValue(f.value, def.type));
        break;
      case 'IN':
      case 'NOT IN': {
        const vals = f.value.split(',').map((v) => v.trim()).filter(Boolean);
        if (vals.length === 0) continue;
        const placeholders = vals.map(() => '?').join(', ');
        clauses.push(`${col} ${f.operator} (${placeholders})`);
        params.push(...vals.map((v) => castValue(v, def.type)));
        break;
      }
      case 'LIKE':
        clauses.push(`${col} LIKE ?`);
        params.push(`%${f.value}%`);
        break;
      case 'IS NULL':
        clauses.push(`${col} IS NULL`);
        break;
      case 'IS NOT NULL':
        clauses.push(`${col} IS NOT NULL`);
        break;
    }
  }

  return {
    sql: clauses.length > 0 ? clauses.join(' AND ') : '1=1',
    params,
  };
}

function castValue(value: string, type: FilterFieldDef['type']): unknown {
  if (type === 'number') {
    const n = Number(value);
    return isNaN(n) ? value : n;
  }
  if (type === 'boolean') return value === 'true' || value === '1';
  return value;
}

// ────────────────────────────────────────────
// 보상 URL 치환
// ────────────────────────────────────────────

export function replaceRewardUrl(
  html: string,
  placeholder: string,
  rewardUrl: string
): string {
  // 전체 일치 치환 (g 플래그)
  return html.split(placeholder).join(rewardUrl);
}

// ────────────────────────────────────────────
// 날짜 포맷
// ────────────────────────────────────────────

export function formatDate(iso: string): string {
  return new Date(iso).toLocaleString('ko-KR', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export function toISOLocal(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

// ────────────────────────────────────────────
// 캠페인 상태 한국어 레이블
// ────────────────────────────────────────────

export const STATUS_LABELS: Record<string, string> = {
  draft: '초안',
  confirmed: '확인 완료',
  extracting: 'DB 추출 중',
  uploading: '업로드 중',
  preparing: '테스트 발송 중',
  awaiting_approval: '승인 대기',
  scheduling: '예약 설정 중',
  scheduled: '예약 완료',
  cancelling: '예약 취소 중',
  sent: '발송 완료',
  failed: '실패',
};

export const STATUS_COLORS: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-600',
  confirmed: 'bg-blue-100 text-blue-700',
  extracting: 'bg-yellow-100 text-yellow-700',
  uploading: 'bg-orange-100 text-orange-700',
  preparing: 'bg-orange-100 text-orange-700',
  awaiting_approval: 'bg-amber-100 text-amber-700',
  scheduling: 'bg-purple-100 text-purple-700',
  scheduled: 'bg-indigo-100 text-indigo-700',
  cancelling: 'bg-slate-100 text-slate-600',
  sent: 'bg-green-100 text-green-700',
  failed: 'bg-red-100 text-red-700',
};

// ────────────────────────────────────────────
// 숫자 포맷 (천 단위 콤마)
// ────────────────────────────────────────────

export function formatNumber(n: number): string {
  return n.toLocaleString('ko-KR');
}

// ────────────────────────────────────────────
// 배열 청크 분할 (Marketo 300명 배치용)
// ────────────────────────────────────────────

export function chunkArray<T>(arr: T[], size: number): T[][] {
  const chunks: T[][] = [];
  for (let i = 0; i < arr.length; i += size) {
    chunks.push(arr.slice(i, i + size));
  }
  return chunks;
}

// ────────────────────────────────────────────
// sleep (rate limit 재시도용)
// ────────────────────────────────────────────

export function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/** 주어진 날짜가 속한 주의 월요일 날짜를 'YYYY-MM-DD'로 반환 (로컬 날짜 기준) */
export function getWeekStart(date: Date = new Date()): string {
  const d = new Date(date);
  const day = d.getDay(); // 0=일, 1=월, ...6=토
  const diff = day === 0 ? -6 : 1 - day; // 월요일로 조정
  d.setDate(d.getDate() + diff);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** weekStart(YYYY-MM-DD, 월요일)로부터 7일(월~일) 날짜 배열 반환 */
export function getWeekDates(weekStart: string): string[] {
  const [y, m, d] = weekStart.split('-').map(Number);
  const pad = (n: number) => String(n).padStart(2, '0');
  return Array.from({ length: 7 }, (_, i) => {
    const dt = new Date(y, m - 1, d + i);
    return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}`;
  });
}

/** 'YYYY-MM-DD' 날짜를 한국어 요일명으로 변환 */
export function getDayLabel(date: string): string {
  const labels = ['일', '월', '화', '수', '목', '금', '토'];
  return labels[new Date(date + 'T00:00:00').getDay()];
}

/** 날짜가 토요일(6) 또는 일요일(0)인지 여부 */
export function isWeekend(date: string): boolean {
  const day = new Date(date + 'T00:00:00').getDay();
  return day === 0 || day === 6;
}
