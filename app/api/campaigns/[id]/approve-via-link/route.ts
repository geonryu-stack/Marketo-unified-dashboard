/**
 * GET  /api/campaigns/[id]/approve-via-link?token=...&expires=...
 *   → 토큰 검증 → 확인 HTML 페이지 반환 (상태 변경 없음)
 *
 * POST /api/campaigns/[id]/approve-via-link  (form submit)
 *   → form body에서 token/expires 읽기 → 재검증 → 내부 approve 호출
 */
import { NextRequest } from 'next/server';
import { verifyToken, escapeHtml } from '@/lib/approval-mailer';

type Ctx = { params: Promise<{ id: string }> };

const APP_URL = process.env.APP_URL || 'http://localhost:3000';

function pageHtml(title: string, content: string): Response {
  return new Response(
    `<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escapeHtml(title)}</title></head><body style="font-family:sans-serif;max-width:480px;margin:60px auto;padding:0 16px;color:#1e293b">${content}</body></html>`,
    { headers: { 'Content-Type': 'text/html; charset=utf-8' } },
  );
}

function expiredPage(id: string): Response {
  return new Response(
    `<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>링크 만료</title></head><body style="font-family:sans-serif;max-width:480px;margin:60px auto;padding:0 16px;color:#1e293b">
    <h2 style="color:#dc2626">링크가 만료되었거나 유효하지 않습니다</h2>
    <p style="color:#475569">72시간이 경과했거나 잘못된 링크입니다. 앱에서 직접 처리해주세요.</p>
    <a href="${APP_URL}/campaigns/${escapeHtml(id)}" style="color:#2563eb;font-weight:600">앱에서 캠페인 보기 →</a>
    </body></html>`,
    { status: 403, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
  );
}

export async function GET(req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const { searchParams } = new URL(req.url);
  const token = searchParams.get('token') ?? '';
  const expires = searchParams.get('expires') ?? '';

  if (!verifyToken('approve', id, token, expires)) {
    return expiredPage(id);
  }

  const safeToken = escapeHtml(token);
  const safeExpires = escapeHtml(expires);

  return pageHtml('발송 승인', `
    <h2>캠페인 발송을 승인하시겠습니까?</h2>
    <p style="color:#475569;font-size:15px">승인하면 Marketo Smart Campaign이 예약됩니다.</p>
    <form method="POST" action="/api/campaigns/${escapeHtml(id)}/approve-via-link">
      <input type="hidden" name="token" value="${safeToken}">
      <input type="hidden" name="expires" value="${safeExpires}">
      <div style="display:flex;gap:12px;margin-top:24px;flex-wrap:wrap">
        <button type="submit" style="padding:12px 24px;background:#16a34a;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer">예, 발송 승인합니다</button>
        <a href="${APP_URL}/campaigns/${escapeHtml(id)}" style="padding:12px 24px;background:#fff;color:#475569;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center">돌아가기</a>
      </div>
    </form>
  `);
}

export async function POST(req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const formData = await req.formData();
  const token = String(formData.get('token') ?? '');
  const expires = String(formData.get('expires') ?? '');

  if (!verifyToken('approve', id, token, expires)) {
    return expiredPage(id);
  }

  const res = await fetch(`${APP_URL}/api/campaigns/${id}/approve`, { method: 'POST' });

  if (res.ok) {
    return pageHtml('승인 완료', `
      <h2 style="color:#16a34a">승인되었습니다 ✅</h2>
      <p>캠페인이 Marketo에 예약되었습니다.</p>
      <a href="${APP_URL}/campaigns/${escapeHtml(id)}" style="display:inline-block;margin-top:12px;padding:10px 20px;background:#fff;color:#475569;border:1px solid #cbd5e1;border-radius:8px;text-decoration:none;font-weight:600">앱에서 캠페인 확인 →</a>
    `);
  }

  const errBody = await res.json().catch(() => ({ error: '알 수 없는 오류' }));
  const errMsg: string = typeof errBody === 'object' && errBody !== null && 'error' in errBody
    ? String((errBody as { error: unknown }).error)
    : '알 수 없는 오류';

  if (res.status === 409) {
    return new Response(
      `<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>이미 처리됨</title></head><body style="font-family:sans-serif;max-width:480px;margin:60px auto;padding:0 16px;color:#1e293b">
      <h2 style="color:#d97706">이미 처리된 캠페인입니다</h2>
      <p style="color:#475569">${escapeHtml(errMsg)}</p>
      <a href="${APP_URL}/campaigns/${escapeHtml(id)}" style="color:#2563eb;font-weight:600">앱에서 캠페인 확인 →</a>
      </body></html>`,
      { status: 409, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
    );
  }

  return pageHtml('오류', `
    <h2 style="color:#dc2626">오류가 발생했습니다</h2>
    <p style="color:#475569">${escapeHtml(errMsg)}</p>
    <a href="${APP_URL}/campaigns/${escapeHtml(id)}" style="color:#2563eb;font-weight:600">앱에서 직접 처리해주세요 →</a>
  `);
}
