import { getStaticLists, getListsByIds } from '@/lib/marketo';
import { NextRequest } from 'next/server';

// 자동발송용 리스트 식별 suffix (MARKETO_LIST_IDS 미사용 시 fallback 필터에 사용)
const LIST_SUFFIX = (process.env.MARKETO_LIST_SUFFIX ?? '_Autosend_Audience').toLowerCase();

// [최우선] 알려진 List ID 직접 조회 — Program 내부 Local List는 programId 필터로 조회 불가한 계정 대응
// 새 그룹 추가 시 .env.local의 MARKETO_LIST_IDS에 List ID 추가 (서버 재시작 필요)
const LIST_IDS = (process.env.MARKETO_LIST_IDS ?? '')
  .split(',').map((s) => parseInt(s.trim(), 10)).filter((n) => !isNaN(n));

// [fallback] Program ID 기반 조회 (계정에 따라 동작할 수도 있음)
const PROGRAM_IDS = (process.env.MARKETO_PROGRAM_IDS ?? '')
  .split(',').map((s) => s.trim()).filter(Boolean);

export async function GET(req: NextRequest) {
  try {
    const { searchParams } = req.nextUrl;
    const programId = searchParams.get('programId');

    let lists;
    if (programId) {
      // 특정 program 지정된 경우 (자동 페어링 등)
      lists = await getStaticLists(programId);
    } else if (LIST_IDS.length > 0) {
      // [최우선] ID 직접 조회 — 가장 신뢰할 수 있는 방법
      lists = await getListsByIds(LIST_IDS);
      // ID 직접 조회 시 suffix 필터 불필요 (이미 정확한 IDs)
      return Response.json({ success: true, data: lists });
    } else if (PROGRAM_IDS.length > 0) {
      // [fallback] Program 기반 병렬 조회 — allSettled로 하나 실패해도 나머지 정상 반환
      const results = await Promise.allSettled(PROGRAM_IDS.map((id) => getStaticLists(id)));
      const merged = results.flatMap((r) => {
        if (r.status === 'fulfilled') return r.value;
        console.warn('[marketo/lists] program fetch failed:', r.reason);
        return [];
      });
      const seen = new Set<number>();
      lists = merged.filter((l) => { if (seen.has(l.id)) return false; seen.add(l.id); return true; });
    } else {
      lists = await getStaticLists();
    }

    const filtered = lists.filter((l) => l.name.toLowerCase().endsWith(LIST_SUFFIX));
    return Response.json({ success: true, data: filtered });
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    return Response.json({ success: false, error: msg }, { status: 500 });
  }
}
