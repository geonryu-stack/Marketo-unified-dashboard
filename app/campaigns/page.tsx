import { getDb } from '@/db/sqlite';
import { Campaign } from '@/lib/types';
import { formatDate, formatNumber } from '@/lib/utils';
import { StatusBadge } from '@/components/ui/status-badge';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import Link from 'next/link';
import { Plus, Send } from 'lucide-react';

export default function CampaignsPage() {
  const db = getDb();
  const campaigns = db
    .prepare('SELECT * FROM campaigns ORDER BY created_at DESC')
    .all() as Campaign[];

  return (
    <div className="p-8 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">캠페인</h1>
          <p className="text-sm text-slate-500 mt-1">이메일 발송 캠페인 목록</p>
        </div>
        <Link
          href="/campaigns/new"
          className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-sm font-semibold text-white transition-colors"
        >
          <Plus className="h-4 w-4" />
          새 캠페인
        </Link>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>전체 캠페인 ({campaigns.length})</CardTitle>
        </CardHeader>
        {campaigns.length === 0 ? (
          <div className="px-5 py-16 text-center">
            <Send className="h-10 w-10 text-slate-300 mx-auto mb-3" />
            <p className="text-slate-500">아직 캠페인이 없습니다.</p>
            <p className="text-sm text-slate-400 mt-1">
              <Link href="/campaigns/new" className="text-indigo-600 hover:underline">새 캠페인</Link>을 생성하여 예약 발송을 시작하세요.
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100">
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-500">캠페인명</th>
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-500">세그먼트</th>
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-500">에셋</th>
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-500">대상자</th>
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-500">예약일시</th>
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-500">상태</th>
                  <th className="px-5 py-3" />
                </tr>
              </thead>
              <tbody>
                {campaigns.map((c) => (
                  <tr key={c.id} className="border-b border-slate-50 hover:bg-slate-50 transition-colors">
                    <td className="px-5 py-3 font-medium text-slate-800">
                      <Link href={`/campaigns/${c.id}`} className="hover:text-indigo-600">
                        {c.name}
                      </Link>
                    </td>
                    <td className="px-5 py-3 text-slate-600">{c.segment_name}</td>
                    <td className="px-5 py-3 text-slate-600">{c.asset_name}</td>
                    <td className="px-5 py-3 text-slate-600">{formatNumber(c.lead_count ?? 0)}명</td>
                    <td className="px-5 py-3 text-slate-600">{formatDate(c.scheduled_at)}</td>
                    <td className="px-5 py-3">
                      <StatusBadge status={c.status} />
                    </td>
                    <td className="px-5 py-3 text-right">
                      <Link href={`/campaigns/${c.id}`} className="text-xs text-indigo-600 hover:underline">상세</Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
