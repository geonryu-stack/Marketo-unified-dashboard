/**
 * lib/approval-mailer.ts
 * 원클릭 승인 링크 — HMAC-SHA256 토큰 생성/검증 + 알림 이메일 발송
 */
import crypto from 'crypto';
import nodemailer from 'nodemailer';
import type { Campaign } from '@/lib/types';

const SECRET = process.env.APPROVAL_SECRET ?? '';

if (!SECRET) {
  console.warn('[approval-mailer] APPROVAL_SECRET 환경변수가 설정되지 않았습니다. 승인 이메일 발송이 비활성화됩니다.');
}

const transporter = nodemailer.createTransport({
  host: process.env.SMTP_HOST,
  port: parseInt(process.env.SMTP_PORT ?? '587', 10),
  secure: false,
  auth: {
    user: process.env.SMTP_USER,
    pass: process.env.SMTP_PASS,
  },
});

const SMTP_FROM = process.env.SMTP_FROM || process.env.SMTP_USER || '';

export function generateToken(action: string, campaignId: string, expiresAt: number): string {
  if (!SECRET) throw new Error('[approval-mailer] APPROVAL_SECRET is not set');
  return crypto
    .createHmac('sha256', SECRET)
    .update(`${action}:${campaignId}:${expiresAt}`)
    .digest('hex');
}

export function verifyToken(action: string, campaignId: string, token: string, expires: string): boolean {
  if (!SECRET) return false;
  const expiresAt = parseInt(expires, 10);
  if (isNaN(expiresAt) || Date.now() > expiresAt) return false;
  const expected = generateToken(action, campaignId, expiresAt);
  const tokenBuf = Buffer.from(token, 'hex');
  const expectedBuf = Buffer.from(expected, 'hex');
  if (tokenBuf.length !== expectedBuf.length) return false;
  return crypto.timingSafeEqual(tokenBuf, expectedBuf);
}

function escapeHtml(str: string): string {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

export async function sendApprovalEmail(
  campaign: Campaign,
  approveUrl: string,
  rejectUrl: string,
): Promise<void> {
  const toList = (process.env.SEND_TEST_EMAIL_TO || '')
    .split(',').map((e) => e.trim()).filter(Boolean);
  if (toList.length === 0) throw new Error('SEND_TEST_EMAIL_TO 환경변수가 설정되지 않았습니다.');

  const appUrl = process.env.APP_URL || 'http://localhost:3000';

  const html = `<!DOCTYPE html>
<html lang="ko">
<head><meta charset="utf-8"><title>승인 요청</title></head>
<body style="font-family:sans-serif;max-width:560px;margin:40px auto;padding:0 16px;color:#1e293b">
  <h2 style="font-size:18px;margin-bottom:4px">[승인 필요] ${escapeHtml(campaign.name)}</h2>
  <p style="color:#64748b;font-size:13px;margin-top:0">테스트 메일을 확인하신 후 승인해주세요</p>
  <table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px">
    <tr><td style="padding:6px 0;color:#64748b;width:90px">캠페인</td><td style="padding:6px 0;font-weight:600">${escapeHtml(campaign.name)}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b">세그먼트</td><td style="padding:6px 0">${escapeHtml(campaign.segment_name)}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b">에셋</td><td style="padding:6px 0">${escapeHtml(campaign.asset_name)}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b">보상 URL</td><td style="padding:6px 0;font-size:12px;word-break:break-all">${escapeHtml(campaign.reward_url)}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b">RTZ 발송</td><td style="padding:6px 0">${escapeHtml(campaign.send_time || '-')}</td></tr>
    <tr><td style="padding:6px 0;color:#64748b">대상자</td><td style="padding:6px 0"><strong>${campaign.lead_count ?? 0}명</strong></td></tr>
  </table>
  <div style="margin:24px 0">
    <a href="${escapeHtml(approveUrl)}" style="display:inline-block;padding:12px 24px;background:#16a34a;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;margin-right:12px">✅ 발송 승인</a>
    <a href="${escapeHtml(rejectUrl)}" style="display:inline-block;padding:12px 24px;background:#fff;color:#475569;text-decoration:none;border-radius:8px;font-weight:600;border:1px solid #cbd5e1">🔄 재검토</a>
  </div>
  <p style="font-size:12px;color:#94a3b8">이 링크는 72시간 후 만료됩니다.<br>앱에서도 직접 처리할 수 있습니다: <a href="${escapeHtml(appUrl)}/campaigns/${escapeHtml(campaign.id)}">${escapeHtml(appUrl)}/campaigns/${escapeHtml(campaign.id)}</a></p>
</body>
</html>`;

  await transporter.sendMail({
    from: SMTP_FROM,
    to: toList.join(', '),
    subject: `[승인 필요] ${campaign.name} 테스트 메일 확인`,
    html,
  });
}
