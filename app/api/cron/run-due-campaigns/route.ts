/**
 * GET /api/cron/run-due-campaigns
 *
 * 예약된 시각이 된 'confirmed' 상태 캠페인을 자동으로 Phase 1 실행합니다.
 * Vercel Cron 또는 외부 cron 서비스에서 1분마다 호출합니다.
 *
 * 인증: Authorization: Bearer {CRON_SECRET} 헤더
 */
import { NextRequest } from 'next/server';
import { getDb } from '@/db/sqlite';
import { Campaign } from '@/lib/types';

const CRON_SECRET = process.env.CRON_SECRET || '';

export async function GET(req: NextRequest) {
  // CRON_SECRET 인증
  const authHeader = req.headers.get('Authorization');
  if (!CRON_SECRET || authHeader !== `Bearer ${CRON_SECRET}`) {
    return Response.json({ success: false, error: 'Unauthorized' }, { status: 401 });
  }

  const appDb = getDb();
  const now = new Date().toISOString();

  // confirmed 상태이고 scheduled_at이 현재 이전인 캠페인 조회
  const due = appDb.prepare(`
    SELECT * FROM campaigns
    WHERE status = 'confirmed' AND scheduled_at <= ?
    ORDER BY scheduled_at ASC
  `).all(now) as Campaign[];

  if (due.length === 0) {
    return Response.json({ success: true, data: { triggered: 0, errors: [] } });
  }

  const baseUrl = req.nextUrl.origin;
  const triggered: string[] = [];
  const errors: { id: string; name: string; error: string }[] = [];

  for (const campaign of due) {
    try {
      const res = await fetch(`${baseUrl}/api/campaigns/${campaign.id}/run`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
      });
      const data = await res.json();
      if (data.success) {
        triggered.push(campaign.id);
      } else {
        errors.push({ id: campaign.id, name: campaign.name, error: data.error ?? '알 수 없는 오류' });
      }
    } catch (err) {
      errors.push({
        id: campaign.id,
        name: campaign.name,
        error: err instanceof Error ? err.message : String(err),
      });
    }
  }

  return Response.json({
    success: true,
    data: { triggered: triggered.length, triggered_ids: triggered, errors },
  });
}
