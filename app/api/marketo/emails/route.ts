import { getMarketoEmails } from '@/lib/marketo';

export const dynamic = 'force-dynamic';

function getFolderConfig(): { id: number; type: 'Folder' | 'Program' } | undefined {
  const rawId = process.env.MARKETO_EMAIL_FOLDER_ID;
  if (!rawId) return undefined;

  const id = parseInt(rawId, 10);
  if (!Number.isFinite(id)) throw new Error(`MARKETO_EMAIL_FOLDER_ID="${rawId}" 은 유효한 정수가 아닙니다.`);

  const rawType = process.env.MARKETO_EMAIL_FOLDER_TYPE ?? 'Folder';
  if (rawType !== 'Folder' && rawType !== 'Program') {
    throw new Error(`MARKETO_EMAIL_FOLDER_TYPE="${rawType}" 은 'Folder' 또는 'Program' 이어야 합니다.`);
  }
  return { id, type: rawType };
}

export async function GET() {
  try {
    const folder = getFolderConfig();
    const emails = await getMarketoEmails(folder?.id, folder?.type);
    return Response.json({ success: true, data: emails });
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    return Response.json({ success: false, error: msg }, { status: 500 });
  }
}
