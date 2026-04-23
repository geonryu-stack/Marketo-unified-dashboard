import { getDb } from '@/db/sqlite';
import { Segment } from '@/lib/types';
import { SegmentForm } from '@/components/segment-form';
import { notFound } from 'next/navigation';

type Props = { params: Promise<{ id: string }> };

export default async function EditSegmentPage({ params }: Props) {
  const { id } = await params;
  const db = getDb();
  const seg = db.prepare('SELECT * FROM segments WHERE id = ?').get(id) as Segment | undefined;
  if (!seg) notFound();

  const parsed = {
    ...seg,
    filters: typeof seg.filters === 'string' ? JSON.parse(seg.filters) : seg.filters,
  };

  return (
    <div className="p-8 max-w-3xl">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-900">세그먼트 편집</h1>
        <p className="text-sm text-slate-500 mt-1">{seg.name}</p>
      </div>
      <SegmentForm initialData={parsed} />
    </div>
  );
}
