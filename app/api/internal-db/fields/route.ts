import { FIELD_DEFS } from '@/lib/field-defs';

export async function GET() {
  return Response.json({ success: true, data: FIELD_DEFS });
}
