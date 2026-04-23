import { getMarketoEmails } from '@/lib/marketo';

export const dynamic = 'force-dynamic';

const FOLDER_ID = process.env.MARKETO_EMAIL_FOLDER_ID
  ? parseInt(process.env.MARKETO_EMAIL_FOLDER_ID, 10)
  : undefined;

export async function GET() {
  try {
    const emails = await getMarketoEmails(FOLDER_ID);
    return Response.json({ success: true, data: emails });
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    return Response.json({ success: false, error: msg }, { status: 500 });
  }
}
