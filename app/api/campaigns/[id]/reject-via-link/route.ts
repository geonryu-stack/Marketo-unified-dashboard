/**
 * GET  /api/campaigns/[id]/reject-via-link?token=...&expires=...
 *   → 토큰 검증 → 재검토 확인 HTML 반환 (상태 변경 없음)
 *
 * POST /api/campaigns/[id]/reject-via-link  (form submit)
 *   → form body에서 token/expires 읽기 → 재검증 → 내부 reject 호출
 */
import { NextRequest } from 'next/server';
import { verifyToken, escapeHtml } from '@/lib/approval-mailer';

type Ctx = { params: Promise<{ id: string }> };

const APP_URL = process.env.APP_URL || 'http://localhost:3000';

function pageHtml(title: string, content: string, status = 200): Response {
  return new Response(
    `<!DOCTYPE html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escapeHtml(title)}</title></head><body style="font-family:sans-serif;max-width:480px;margin:60px auto;padding:0 16px;color:#1e293b">${content}</body></html>`,
    { status, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
  );
}

function expiredPage(id: string): Response {
  return pageHtml('링크 만료', `
    <h2 style="color:#dc2626">링크가 만료되었거나 유효하지 않습니다</h2>
    <p style="color:#475569">72시간이 경과했거나 잘못된 링크입니다. 앱에서 직접 처리해주세요.</p>
    <a href="${APP_URL}/campaigns/${escapeHtml(id)}" style="color:#2563eb;font-weight:600">앱에서 캠페인 보기 →</a>
  `, 403);
}

export async function GET(req: NextRequest, { params }: Ctx) {
  const { id } = await params;
  const { searchParams } = new URL(req.url);
  const token = searchParams.get('token') ?? '';
  const expires = searchParams.get('expires') ?? '';

  if (!verifyToken('reject', id, token, expires)) {
    return expiredPage(id);
  }

  const safeToken = escapeHtml(token);
  const safeExpires = escapeHtml(expires);

  return pageHtml('재검토 요청', `
    <h2>재검토를 요청하시겠습니까?</h2>
    <p style="color:#475569;font-size:15px">재검토를 요청하면 캠페인이 draft 상태로 돌아갑니다.<br>앱에서 수정 후 Phase 1을 다시 실행하세요.</p>
    <form method="POST" action="/api/campaigns/${escapeHtml(id)}/reject-via-link">
      <input type="hidden" name="token" value="${safeToken}">
      <input type="hidden" name="expires" value="${safeExpires}">
      <div style="display:flex;gap:12px;margin-top:24px;flex-wrap:wrap">
        <button type="submit" style="padding:12px 24px;background:#d97706;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer">예, 재검토 요청합니다</button>
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

  if (!verifyToken('reject', id, token, expires)) {
    return expiredPage(id);
  }

  const res = await fetch(`${APP_URL}/api/campaigns/${id}/reject`, { method: 'POST' });

  if (res.ok) {
    return pageHtml('재검토 요청 완료', `
      <h2 style="color:#d97706">재검토가 요청되었습니다 🔄</h2>
      <p>캠페인이 draft 상태로 돌아갔습니다.</p>
      <p style="color:#475569;font-size:14px;margin-top:4px">다음 단계: 앱에서 캠페인을 수정한 후 Phase 1을 다시 실행하세요.</p>
      <a href="${APP_URL}/campaigns/${escapeHtml(id)}" style="display:inline-block;margin-top:16px;padding:10px 20px;background:#fff;color:#475569;border:1px solid #cbd5e1;border-radius:8px;text-decoration:none;font-weight:600">앱에서 캠페인 수정하기 →</a>
    `);
  }

  const errBody = await res.json().catch(() => ({ error: '알 수 없는 오류' }));
  const errMsg: string = typeof errBody === 'object' && errBody !== null && 'error' in errBody
    ? String((errBody as { error: unknown }).error)
    : '알 수 없는 오류';

  if (res.status === 409) {
    return pageHtml('이미 처리됨', `
      <h2 style="color:#d97706">이미 처리된 캠페인입니다</h2>
      <p style="color:#475569">${escapeHtml(errMsg)}</p>
      <a href="${APP_URL}/campaigns/${escapeHtml(id)}" style="color:#2563eb;font-weight:600">앱에서 캠페인 확인 →</a>
    `, 409);
  }

  return pageHtml('오류', `
    <h2 style="color:#dc2626">오류가 발생했습니다</h2>
    <p style="color:#475569">${escapeHtml(errMsg)}</p>
    <a href="${APP_URL}/campaigns/${escapeHtml(id)}" style="color:#2563eb;font-weight:600">앱에서 직접 처리해주세요 →</a>
  `);
}
