import { getDb } from '@/db/sqlite';
import { Campaign, JobLog } from '@/lib/types';
import { notFound } from 'next/navigation';
import { formatDate, formatNumber } from '@/lib/utils';
import { StatusBadge } from '@/components/ui/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CampaignActions } from '@/components/campaign-actions';
import { JobLogList } from '@/components/job-log-list';
import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';

type Props = { params: Promise<{ id: string }> };

export default async function CampaignDetailPage({ params }: Props) {
  const { id } = await params;
  const db = getDb();

  const campaign = db.prepare('SELECT * FROM campaigns WHERE id = ?').get(id) as Campaign | undefined;
  if (!campaign) notFound();

  const logs = db
    .prepare('SELECT * FROM job_logs WHERE campaign_id = ? ORDER BY created_at ASC')
    .all(id) as JobLog[];

  const canRun = campaign.status === 'draft' || campaign.status === 'confirmed';

  const infoItems = [
    { label: '세그먼트', value: campaign.segment_name },
    { label: '에셋', value: campaign.asset_name },
    { label: '대상자 수', value: `${formatNumber(campaign.lead_count ?? 0)}명` },
    { label: 'Phase 1 실행 일시', value: formatDate(campaign.scheduled_at) },
    { label: 'RTZ 발송 시각', value: campaign.send_time || '-' },
    { label: '보상 URL', value: campaign.reward_url || '-' },
    { label: 'Marketo Audience List ID', value: campaign.marketo_list_id || '-' },
    { label: 'Marketo List 이름', value: campaign.marketo_list_name || '-' },
    { label: '생성일', value: formatDate(campaign.created_at) },
  ];

  return (
    <div className="p-8 space-y-6 max-w-3xl">
      <div>
        <Link href="/campaigns" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700 mb-3">
          <ArrowLeft className="h-3.5 w-3.5" />
          캠페인 목록
        </Link>
        <div className="flex items-start justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold text-slate-900">{campaign.name}</h1>
            <div className="flex items-center gap-2 mt-1.5">
              <StatusBadge status={campaign.status} />
              {campaign.error_message && (
                <span className="text-xs text-red-500">{campaign.error_message}</span>
              )}
            </div>
          </div>
          <CampaignActions campaign={campaign} canRun={canRun} />
        </div>
      </div>

      {/* 승인 대기 안내 배너 */}
      {campaign.status === 'awaiting_approval' && (
        <div className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <p className="font-semibold mb-1">테스트 메일을 확인해주세요</p>
          <p>수신된 테스트 메일에서 오탈자, 이미지, 보상 URL 클릭 및 보상 획득을 확인한 뒤 <strong>발송 승인</strong> 버튼을 눌러주세요. 문제가 있다면 <strong>재검토</strong>를 눌러 초안으로 되돌릴 수 있습니다.</p>
        </div>
      )}

      {/* Campaign Info */}
      <Card>
        <CardHeader><CardTitle>캠페인 정보</CardTitle></CardHeader>
        <CardContent className="py-4">
          <dl className="grid grid-cols-2 gap-x-6 gap-y-3">
            {infoItems.map(({ label, value }) => (
              <div key={label}>
                <dt className="text-xs text-slate-400">{label}</dt>
                <dd className="text-sm text-slate-800 font-medium mt-0.5 break-all">{value}</dd>
              </div>
            ))}
          </dl>
        </CardContent>
      </Card>

      {/* Job Logs */}
      <Card>
        <CardHeader><CardTitle>실행 로그</CardTitle></CardHeader>
        <CardContent className="py-0">
          <JobLogList campaignId={campaign.id} initialLogs={logs} isRunning={
            campaign.status === 'extracting' || campaign.status === 'uploading' ||
            campaign.status === 'preparing' || campaign.status === 'scheduling'
          } />
        </CardContent>
      </Card>
    </div>
  );
}
