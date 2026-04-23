import { SegmentForm } from '@/components/segment-form';

export default function NewSegmentPage() {
  return (
    <div className="p-8 max-w-3xl">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-900">새 세그먼트</h1>
        <p className="text-sm text-slate-500 mt-1">필터 조건으로 발송 대상자 그룹을 정의합니다.</p>
      </div>
      <SegmentForm />
    </div>
  );
}
