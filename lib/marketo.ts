/**
 * Marketo REST API Client
 * - OAuth 2.0 Client Credentials (토큰 캐싱)
 * - Rate Limit: 100 calls / 20초 (CONSTRAINT-06)
 * - 리드 업로드 배치: 최대 300명/요청 (CONSTRAINT-06)
 */

import { chunkArray, sleep } from './utils';
import type { MarketoEmailItem, AssetLibraryItem, Campaign } from './types';

const MUNCHKIN_ID = process.env.MARKETO_MUNCHKIN_ID || '';
const CLIENT_ID = process.env.MARKETO_CLIENT_ID || '';
const CLIENT_SECRET = process.env.MARKETO_CLIENT_SECRET || '';

const BASE_URL = `https://${MUNCHKIN_ID}.mktorest.com`;

// ────────────────────────────────────────────────────
// Token 캐시
// ────────────────────────────────────────────────────

let _token: string | null = null;
let _tokenExpiresAt = 0;

async function getAccessToken(): Promise<string> {
  if (_token && Date.now() < _tokenExpiresAt - 60_000) return _token;

  const url = `${BASE_URL}/identity/oauth/token?grant_type=client_credentials&client_id=${CLIENT_ID}&client_secret=${CLIENT_SECRET}`;
  const res = await fetch(url, { method: 'GET' });
  if (!res.ok) throw new Error(`Marketo token error: ${res.status}`);

  const data = await res.json();
  _token = data.access_token as string;
  _tokenExpiresAt = Date.now() + (data.expires_in as number) * 1000;
  return _token;
}

// ────────────────────────────────────────────────────
// 공통 요청 헬퍼 (rate limit 재시도)
// ────────────────────────────────────────────────────

async function mkRequest<T>(
  method: string,
  path: string,
  body?: unknown,
  retries = 3
): Promise<T> {
  const token = await getAccessToken();
  const url = `${BASE_URL}${path}`;

  for (let attempt = 0; attempt < retries; attempt++) {
    const res = await fetch(url, {
      method,
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      body: body ? JSON.stringify(body) : undefined,
    });

    // Rate limit: 606 or 607
    if (res.status === 429 || res.status === 606 || res.status === 607) {
      await sleep(20_000);
      continue;
    }

    if (!res.ok) {
      const txt = await res.text();
      throw new Error(`Marketo API ${method} ${path} → ${res.status}: ${txt}`);
    }

    const json = await res.json();
    if (json.errors && json.errors.length > 0) {
      const err = json.errors[0];
      // 606/607: rate limit
      if (err.code === '606' || err.code === '607') {
        await sleep(20_000);
        continue;
      }
      throw new Error(`Marketo error ${err.code}: ${err.message}`);
    }
    return json as T;
  }
  throw new Error(`Marketo request failed after ${retries} retries`);
}

// ────────────────────────────────────────────────────
// Static Lists
// ────────────────────────────────────────────────────

export async function getStaticLists(programId?: string) {
  const qs = programId ? `?programId=${programId}` : '';
  const data = await mkRequest<{ result: MarketoListRecord[] }>('GET', `/rest/v1/lists.json${qs}`);
  return data.result ?? [];
}

// ID 목록으로 Static List 직접 조회 — programId 필터가 작동하지 않는 계정(Local List)에 사용
export async function getListsByIds(ids: number[]): Promise<MarketoListRecord[]> {
  if (ids.length === 0) return [];
  const data = await mkRequest<{ result: MarketoListRecord[] }>(
    'GET', `/rest/v1/lists.json?id=${ids.join(',')}`
  );
  return data.result ?? [];
}

export async function createStaticList(name: string, programId?: number): Promise<MarketoListRecord> {
  const body: Record<string, unknown> = { name };
  if (programId) body.programId = programId;
  const data = await mkRequest<{ result: MarketoListRecord[] }>('POST', '/rest/v1/lists.json', body);
  return data.result[0];
}

export interface MarketoListRecord {
  id: number;
  name: string;
  programName?: string;
  createdAt: string;
  updatedAt: string;
}

// ────────────────────────────────────────────────────
// Leads (리드 업로드) — 배치 300명 CONSTRAINT-06
// ────────────────────────────────────────────────────

export async function upsertLeads(emails: string[]): Promise<number[]> {
  const leads = emails.map((email) => ({ email }));
  const batches = chunkArray(leads, 300);
  const ids: number[] = [];

  for (const batch of batches) {
    const data = await mkRequest<{ result: { id: number; status: string }[] }>(
      'POST',
      '/rest/v1/leads.json',
      { action: 'createOrUpdate', input: batch, lookupField: 'email' }
    );
    ids.push(...(data.result ?? []).map((r) => r.id));
  }
  return ids;
}

export async function addLeadsToList(listId: number, leadIds: number[]): Promise<void> {
  const batches = chunkArray(leadIds, 300);
  for (const batch of batches) {
    const input = batch.map((id) => ({ id }));
    await mkRequest('POST', `/rest/v1/lists/${listId}/leads.json`, { input });
  }
}

// ────────────────────────────────────────────────────
// Email Assets
// ────────────────────────────────────────────────────

export interface MarketoEmailRecord {
  id: number;
  name: string;
  subject?: { value: string };
  preHeader?: string;
  status?: string;
  folder?: { id: number; type: string };
  createdAt: string;
  updatedAt: string;
}

export async function cloneEmail(
  sourceEmailId: number,
  newName: string,
  folderId?: number,
  folderType: 'Folder' | 'Program' = 'Folder'
): Promise<MarketoEmailRecord> {
  const body: Record<string, unknown> = { name: newName };
  if (folderId) body.folder = { id: folderId, type: folderType };

  const data = await mkRequest<{ result: MarketoEmailRecord[] }>(
    'POST',
    `/rest/asset/v1/email/${sourceEmailId}/clone.json`,
    body
  );
  return data.result[0];
}

// ────────────────────────────────────────────────────
// Email Content (URL 치환용)
// ────────────────────────────────────────────────────

export interface EmailContentSection {
  htmlId: string;
  contentType: string;
  value: { type: string; value: string }[];
}

/** Clone된 이메일의 콘텐츠 섹션 목록을 가져옵니다. */
export async function getEmailContentSections(emailId: number): Promise<EmailContentSection[]> {
  const data = await mkRequest<{ result: EmailContentSection[] }>(
    'GET',
    `/rest/asset/v1/email/${emailId}/content.json`
  );
  return data.result ?? [];
}

/**
 * 특정 콘텐츠 섹션의 HTML을 업데이트합니다.
 * Marketo Asset API는 form-encoded body를 사용합니다.
 */
export async function updateEmailContentSection(
  emailId: number,
  htmlId: string,
  newHtml: string
): Promise<void> {
  const token = await getAccessToken();
  const url = `${BASE_URL}/rest/asset/v1/email/${emailId}/content/${encodeURIComponent(htmlId)}.json`;
  const formData = new URLSearchParams();
  formData.set('type', 'HTML');
  formData.set('value', newHtml);

  const res = await fetch(url, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: formData.toString(),
  });
  if (!res.ok) {
    const txt = await res.text();
    throw new Error(`updateEmailContentSection(${htmlId}) error ${res.status}: ${txt}`);
  }
}

/**
 * Clone된 이메일 전체 콘텐츠 섹션을 순회하여
 * placeholder를 rewardUrl로 치환합니다.
 * 치환된 섹션 수를 반환합니다.
 */
export async function injectRewardUrl(
  emailId: number,
  placeholder: string,
  rewardUrl: string
): Promise<number> {
  const sections = await getEmailContentSections(emailId);
  let injectedCount = 0;

  for (const section of sections) {
    const htmlPart = section.value.find((v) => v.type === 'HTML');
    if (htmlPart && htmlPart.value.includes(placeholder)) {
      const newHtml = htmlPart.value.split(placeholder).join(rewardUrl);
      await updateEmailContentSection(emailId, section.htmlId, newHtml);
      injectedCount++;
    }
  }
  return injectedCount;
}

export async function updateEmailSubject(emailId: number, subject: string): Promise<void> {
  await mkRequest('POST', `/rest/asset/v1/email/${emailId}/content.json`, {
    content: [{ type: 'Text', value: subject }],
    type: 'subject',
  });
}

export async function approveEmail(emailId: number): Promise<void> {
  await mkRequest('POST', `/rest/asset/v1/email/${emailId}/approveDraft.json`);
}

// ────────────────────────────────────────────────────
// Smart Campaigns
// ────────────────────────────────────────────────────

export interface MarketoCampaignRecord {
  id: number;
  name: string;
  status: string;
  type: string;
  programId?: number;
  programName?: string;
  createdAt: string;
  updatedAt: string;
}

export async function getSmartCampaigns(programId?: number): Promise<MarketoCampaignRecord[]> {
  const qs = programId ? `?programId=${programId}` : '';
  const data = await mkRequest<{ result: MarketoCampaignRecord[] }>(
    'GET',
    `/rest/v1/campaigns.json${qs}`
  );
  return data.result ?? [];
}

export async function scheduleCampaign(
  campaignId: number,
  scheduledAt: string,
  tokens?: { name: string; value: string }[]
): Promise<void> {
  // Marketo API spec: tokens must be nested inside `input`, not at the top level.
  const input: Record<string, unknown> = { runAt: scheduledAt };
  if (tokens && tokens.length > 0) input.tokens = tokens;
  await mkRequest('POST', `/rest/v1/campaigns/${campaignId}/schedule.json`, { input });
}

// ────────────────────────────────────────────────────
// Email Programs (RTZ 발송)
// ────────────────────────────────────────────────────

export interface MarketoEmailProgramRecord {
  id: number;
  name: string;
  type: string;
  emailId?: number;
  emailName?: string;
  status?: string;
  createdAt: string;
  updatedAt: string;
}

/** Email Program 조회 — emailId 획득용 */
export async function getEmailProgram(programId: number): Promise<MarketoEmailProgramRecord> {
  const data = await mkRequest<{ result: MarketoEmailProgramRecord[] }>(
    'GET', `/rest/asset/v1/emailProgram/${programId}.json`
  );
  return data.result[0];
}

/** Email Program의 My Token 값을 일괄 설정 */
export async function setProgramMyTokens(
  programId: number,
  tokens: { name: string; value: string; type?: string }[]
): Promise<void> {
  await mkRequest('POST', `/rest/asset/v1/program/${programId}/tokens.json`, { tokens });
}

/** Email Program 스케줄 설정
 *  recipientTimeZone=true 시 수신자 현지 시간 기준 발송 (RTZ) */
export async function scheduleEmailProgram(
  programId: number,
  startDate: string,   // "YYYY-MM-DD"
  startTime: string,   // "HH:MM" 또는 "HH:MM:SS"
  recipientTimeZone = false
): Promise<void> {
  const body: Record<string, unknown> = { startDate, startTime };
  if (recipientTimeZone) body.recipientTimeZone = true;
  await mkRequest(
    'PUT',
    `/rest/asset/v1/emailProgram/${programId}/schedule.json`,
    body
  );
}

/** Email Program Approve */
export async function approveEmailProgram(programId: number): Promise<void> {
  await mkRequest('POST', `/rest/asset/v1/emailProgram/${programId}/approve.json`);
}

/** Email Program Unapprove (스케줄 수정 전 필요)
 *  702: already unapproved, 709: not found — 무시. 그 외 에러는 re-throw. */
export async function unapproveEmailProgram(programId: number): Promise<void> {
  try {
    await mkRequest('POST', `/rest/asset/v1/emailProgram/${programId}/unapprove.json`);
  } catch (err) {
    if (err instanceof Error && /Marketo error (702|709):/.test(err.message)) return;
    throw err;
  }
}

/** 테스트 메일 발송 (form-encoded, Marketo Asset API 규격) */
export async function sendSampleEmail(emailId: number, toEmail: string): Promise<void> {
  const token = await getAccessToken();
  const url = `${BASE_URL}/rest/asset/v1/email/${emailId}/sendSample.json`;
  const formData = new URLSearchParams();
  formData.set('emailAddress', toEmail);

  const res = await fetch(url, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: formData.toString(),
  });
  if (!res.ok) {
    const txt = await res.text();
    throw new Error(`sendSampleEmail(${emailId}) error ${res.status}: ${txt}`);
  }
}

// ────────────────────────────────────────────────────
// Static List 멤버 관리 (고정 리스트 갱신용)
// ────────────────────────────────────────────────────

/** Static List의 리드 ID 전체 조회 (페이지네이션) */
export async function getListLeadIds(listId: number): Promise<number[]> {
  const ids: number[] = [];
  let nextToken: string | undefined;

  do {
    const qs = `?fields=id&batchSize=300${nextToken ? `&nextPageToken=${encodeURIComponent(nextToken)}` : ''}`;
    const data = await mkRequest<{
      result: { id: number }[];
      nextPageToken?: string;
      moreResult?: boolean;
    }>('GET', `/rest/v1/lists/${listId}/leads.json${qs}`);
    for (const r of data.result ?? []) ids.push(r.id);
    nextToken = data.moreResult ? data.nextPageToken : undefined;
  } while (nextToken);

  return ids;
}

/** Static List에서 리드 제거 (300명씩 배치) */
export async function removeLeadsFromList(listId: number, leadIds: number[]): Promise<void> {
  const batches = chunkArray(leadIds, 300);
  for (const batch of batches) {
    await mkRequest('DELETE', `/rest/v1/lists/${listId}/leads.json`, {
      input: batch.map((id) => ({ id })),
    });
  }
}

// ────────────────────────────────────────────────────
// Email Asset List (새 발송 대시보드용)
// ────────────────────────────────────────────────────

/** Marketo 이메일 에셋 목록 조회 (최대 200개, 최신 수정순)
 *  folderId가 지정되면 해당 Folder 내 approved 에셋만 반환.
 *  709(No assets found)는 빈 배열로 처리. 그 외 오류(711 등)는 그대로 throw. */
export async function getMarketoEmails(folderId?: number, folderType: 'Folder' | 'Program' = 'Folder'): Promise<MarketoEmailItem[]> {
  const folderParam = Number.isFinite(folderId)
    ? `&folder=${encodeURIComponent(JSON.stringify({ id: folderId, type: folderType }))}`
    : '';
  try {
    const data = await mkRequest<{ result: MarketoEmailItem[] }>(
      'GET',
      `/rest/asset/v1/emails.json?maxReturn=200&status=approved&orderBy=updatedAt&sortOrder=DESC${folderParam}`
    );
    return data.result ?? [];
  } catch (err) {
    // 709 = No assets found — 폴더가 비어 있는 정상 케이스, 빈 배열 반환
    if (err instanceof Error && /Marketo error 709:/.test(err.message)) return [];
    // 711 등 폴더 설정 오류는 숨기지 않고 throw해 호출자가 인지하게 한다
    throw err;
  }
}

// ────────────────────────────────────────────────────
// Email Program Token 페이로드 빌더
// ────────────────────────────────────────────────────

/**
 * 에셋 + 캠페인 데이터를 Marketo Program My Token API 페이로드로 변환.
 * SC schedule tokens({{my.xxx}}) → EP My Token API(my.xxx + type 포함)
 */
export function buildEpTokenPayload(
  asset: AssetLibraryItem,
  campaign: Campaign
): { name: string; value: string; type: string }[] {
  const strip = (t: string) => t.replace(/^\{\{/, '').replace(/\}\}$/, '');
  const out: { name: string; value: string; type: string }[] = [];

  if (asset.marketo_token_reward_url && campaign.reward_url)
    out.push({ name: strip(asset.marketo_token_reward_url), value: campaign.reward_url, type: 'URL' });
  if (asset.marketo_token_image && asset.image_url)
    out.push({ name: strip(asset.marketo_token_image), value: asset.image_url, type: 'URL' });
  if (asset.marketo_token_subject && asset.subject)
    out.push({ name: strip(asset.marketo_token_subject), value: asset.subject, type: 'Text' });
  if (asset.marketo_token_emoji && asset.emoji)
    out.push({ name: strip(asset.marketo_token_emoji), value: asset.emoji, type: 'Text' });
  if (asset.marketo_token_preheader && asset.preheader)
    out.push({ name: strip(asset.marketo_token_preheader), value: asset.preheader, type: 'Text' });
  if (asset.marketo_token_body && asset.body_text)
    out.push({ name: strip(asset.marketo_token_body), value: asset.body_text, type: 'Text' });

  return out;
}
