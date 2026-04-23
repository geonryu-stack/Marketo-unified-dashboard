import { getDb } from '@/db/sqlite';
import { Campaign, RecurringSegmentRow } from '@/lib/types';
import { formatDate, formatNumber, STATUS_LABELS } from '@/lib/utils';
import { StatusBadge } from '@/components/ui/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import Link from 'next/link';
import {
  Send, Users, Image as ImageIcon, CheckCircle2, Clock, AlertCircle,
} from 'lucide-react';
import { RecurringGroupDashboard } from '@/components/recurring-group-dashboard';

export const dynamic = 'force-dynamic';

export default function DashboardPage() {
  const db = getDb();

  const totalCampaigns = (db.prepare('SELECT COUNT(*) AS c FROM campaigns').get() as { c: number }).c;
  const scheduledCount = (db.prepare("SELECT COUNT(*) AS c FROM campaigns WHERE status='scheduled'").get() as { c: number }).c;
  const sentCount = (db.prepare("SELECT COUNT(*) AS c FROM campaigns WHERE status='sent'").get() as { c: number }).c;
  const failedCount = (db.prepare("SELECT COUNT(*) AS c FROM campaigns WHERE status='failed'").get() as { c: number }).c;
  const totalSegments = (db.prepare('SELECT COUNT(*) AS c FROM segments').get() as { c: number }).c;
  const totalAssets = (db.prepare('SELECT COUNT(*) AS c FROM asset_library').get() as { c: number }).c;

  const recentCampaigns = db
    .prepare('SELECT * FROM campaigns ORDER BY created_at DESC LIMIT 10')
    .all() as Campaign[];

  const recurringSegments = db.prepare(`
    SELECT
      s.id, s.name, s.send_day_of_week, s.recurring_send_time,
      c.id   AS latest_campaign_id,
      c.name AS latest_campaign_name,
      c.status AS latest_campaign_status
    FROM segments s
    LEFT JOIN campaigns c ON c.id = (
      SELECT id FROM campaigns WHERE segment_id = s.id ORDER BY created_at DESC LIMIT 1
    )
    WHERE s.is_recurring = 1
    ORDER BY s.name ASC
  `).all() as RecurringSegmentRow[];

  const assetOptions = db.prepare(
    'SELECT id, name, emoji FROM asset_library ORDER BY name ASC'
  ).all() as { id: string; name: string; emoji: string }[];

  const stats = [
    { label: '전체 캠페인', value: totalCampaigns, icon: Send, color: 'text-indigo-600', bg: 'bg-indigo-50' },
    { label: '예약 완료', value: scheduledCount, icon: Clock, color: 'text-blue-600', bg: 'bg-blue-50' },
    { label: '발송 완료', value: sentCount, icon: CheckCircle2, color: 'text-green-600', bg: 'bg-green-50' },
    { label: '오류', value: failedCount, icon: AlertCircle, color: 'text-red-600', bg: 'bg-red-50' },
    { label: '세그먼트', value: totalSegments, icon: Users, color: 'text-violet-600', bg: 'bg-violet-50' },
    { label: '에셋', value: totalAssets, icon: ImageIcon, color: 'text-amber-600', bg: 'bg-amber-50' },
  ];

  return (
    <div className="p-8 space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-900">대시보드</h1>
        <p className="text-sm text-slate-500 mt-1">Marketo 발송 자동화 현황</p>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        {stats.map(({ label, value, icon: Icon, color, bg }) => (
          <Card key={label}>
            <CardContent className="py-4 px-4">
              <div className={`inline-flex items-center justify-center rounded-lg p-2 ${bg} mb-3`}>
                <Icon className={`h-5 w-5 ${color}`} />
              </div>
              <p className="text-2xl font-bold text-slate-900">{formatNumber(value)}</p>
              <p className="text-xs text-slate-500 mt-0.5">{label}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Recent Campaigns */}
      <Card>
        <CardHeader>
          <CardTitle>최근 캠페인</CardTitle>
          <Link href="/campaigns" className="text-xs text-indigo-600 hover:text-indigo-500 font-medium">
            전체 보기 →
          </Link>
        </CardHeader>
        <div className="overflow-x-auto">
          {recentCampaigns.length === 0 ? (
            <div className="px-5 py-10 text-center text-sm text-slate-400">
              아직 캠페인이 없습니다.{' '}
              <Link href="/campaigns/new" className="text-indigo-600 hover:underline">
                새 캠페인 만들기
              </Link>
            </div>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100">
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-500">캠페인명</th>
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-500">에셋</th>
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-500">대상자</th>
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-500">예약일시</th>
                  <th className="text-left px-5 py-3 text-xs font-medium text-slate-500">상태</th>
                </tr>
              </thead>
              <tbody>
                {recentCampaigns.map((c) => (
                  <tr key={c.id} className="border-b border-slate-50 hover:bg-slate-50 transition-colors">
                    <td className="px-5 py-3 font-medium text-slate-800">
                      <Link href={`/campaigns/${c.id}`} className="hover:text-indigo-600">
                        {c.name}
                      </Link>
                    </td>
                    <td className="px-5 py-3 text-slate-600">{c.asset_name}</td>
                    <td className="px-5 py-3 text-slate-600">{formatNumber(c.lead_count ?? 0)}명</td>
                    <td className="px-5 py-3 text-slate-600">{formatDate(c.scheduled_at)}</td>
                    <td className="px-5 py-3">
                      <StatusBadge status={c.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </Card>

      {/* 반복 발송 그룹 */}
      {recurringSegments.length > 0 && (
        <section>
          <h2 className="text-lg font-semibold text-slate-800 mb-4">반복 발송 그룹</h2>
          <RecurringGroupDashboard segments={recurringSegments} assets={assetOptions} />
        </section>
      )}

      {/* Quick Links */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Link href="/segments/new">
          <Card className="hover:border-indigo-300 hover:shadow-md transition-all cursor-pointer">
            <CardContent className="py-5">
              <Users className="h-6 w-6 text-indigo-500 mb-2" />
              <p className="font-semibold text-slate-800">새 세그먼트</p>
              <p className="text-xs text-slate-500 mt-0.5">사내 DB에서 대상자 그룹 생성</p>
            </CardContent>
          </Card>
        </Link>
        <Link href="/assets">
          <Card className="hover:border-amber-300 hover:shadow-md transition-all cursor-pointer">
            <CardContent className="py-5">
              <ImageIcon className="h-6 w-6 text-amber-500 mb-2" />
              <p className="font-semibold text-slate-800">에셋 라이브러리</p>
              <p className="text-xs text-slate-500 mt-0.5">이미지, 텍스트 세트 관리</p>
            </CardContent>
          </Card>
        </Link>
        <Link href="/campaigns/new">
          <Card className="hover:border-green-300 hover:shadow-md transition-all cursor-pointer">
            <CardContent className="py-5">
              <Send className="h-6 w-6 text-green-500 mb-2" />
              <p className="font-semibold text-slate-800">새 캠페인</p>
              <p className="text-xs text-slate-500 mt-0.5">세그먼트 + 에셋 조합 예약 발송</p>
            </CardContent>
          </Card>
        </Link>
      </div>
    </div>
  );
}
