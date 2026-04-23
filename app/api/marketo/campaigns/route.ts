import { getSmartCampaigns } from '@/lib/marketo';
import { NextRequest } from 'next/server';

// 자동발송용 캠페인 식별 suffix — 이 패턴으로 끝나는 캠페인만 드롭다운에 표시
// 새 그룹 추가 시 Marketo 파일명을 이 suffix로 끝내면 자동 반영됨
const CAMPAIGN_SUFFIX = (process.env.MARKETO_CAMPAIGN_SUFFIX ?? '_Autosend').toLowerCase();

export async function GET(req: NextRequest) {
  try {
    const { searchParams } = req.nextUrl;
    const programId = searchParams.get('programId');
    const campaigns = await getSmartCampaigns(programId ? parseInt(programId, 10) : undefined);
    const filtered = campaigns.filter((c) => c.name.toLowerCase().endsWith(CAMPAIGN_SUFFIX));
    return Response.json({ success: true, data: filtered });
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    return Response.json({ success: false, error: msg }, { status: 500 });
  }
}
