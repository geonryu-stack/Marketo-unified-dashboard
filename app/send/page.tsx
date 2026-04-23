import { getDb } from '@/db/sqlite';
import { SendGroup } from '@/lib/types';
import { SendPageClient } from '@/components/send/send-page-client';

export const dynamic = 'force-dynamic';

export default function SendPage() {
  const db = getDb();
  const groups = db
    .prepare('SELECT * FROM groups ORDER BY sort_order ASC')
    .all() as SendGroup[];

  return (
    <div className="flex flex-col h-full">
      <div className="px-8 py-6 border-b border-slate-200 bg-white">
        <h1 className="text-xl font-bold text-slate-900">새 발송 설정</h1>
        <p className="text-sm text-slate-500 mt-1">
          발송 그룹을 선택하고 이번 주 요일별 에셋·URL·발송 시각을 설정하세요.
        </p>
      </div>
      <SendPageClient initialGroups={groups} />
    </div>
  );
}
