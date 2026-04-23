import { getMarketoEmails } from '@/lib/marketo';

export const dynamic = 'force-dynamic';

export async function GET() {
  try {
    const emails = await getMarketoEmails();
    return Response.json({ success: true, data: emails });
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    return Response.json({ success: false, error: msg }, { status: 500 });
  }
}
