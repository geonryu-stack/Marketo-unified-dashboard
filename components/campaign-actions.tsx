'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { Campaign } from '@/lib/types';
import { Button } from './ui/button';
import { Play, Trash2, RefreshCw, CheckCheck, RotateCcw, XCircle, CalendarCheck, ShieldCheck, Copy } from 'lucide-react';

interface CampaignActionsProps {
  campaign: Campaign;
  canRun: boolean;
}

export function CampaignActions({ campaign, canRun }: CampaignActionsProps) {
  const router = useRouter();
  const [running, setRunning] = useState(false);
  const [error, setError] = useState('');

  const handleRun = async () => {
    if (!confirm(`캠페인 "${campaign.name}"을 실행할까요?\n\n실행 전 아래 사항을 확인하세요:\n- 보상 URL: ${campaign.reward_url || '미입력'}\n- 예약 일시: ${campaign.scheduled_at}`)) return;

    setRunning(true);
    setError('');
    try {
      const res = await fetch(`/api/campaigns/${campaign.id}/run`, { method: 'POST' });
      const data = await res.json();
      if (!data.success) throw new Error(data.error);
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : '실행 실패');
    } finally {
      setRunning(false);
    }
  };

  const handleApprove = async () => {
    if (!confirm(`테스트 메일을 확인하셨나요?\n\n아래 사항을 모두 확인한 후 승인하세요:\n- 오탈자 없음\n- 이미지 정상 표시\n- 보상 URL 클릭 및 보상 획득 확인\n\n승인하면 발송이 예약됩니다.`)) return;

    setRunning(true);
    setError('');
    try {
      const res = await fetch(`/api/campaigns/${campaign.id}/approve`, { method: 'POST' });
      const data = await res.json();
      if (!data.success) throw new Error(data.error);
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : '승인 실패');
    } finally {
      setRunning(false);
    }
  };

  const handleReject = async () => {
    if (!confirm('재검토를 요청하면 캠페인이 초안 상태로 돌아갑니다.\n내용을 수정한 뒤 다시 실행하세요.')) return;

    setRunning(true);
    setError('');
    try {
      const res = await fetch(`/api/campaigns/${campaign.id}/reject`, { method: 'POST' });
      const data = await res.json();
      if (!data.success) throw new Error(data.error);
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : '재검토 요청 실패');
    } finally {
      setRunning(false);
    }
  };

  const handleConfirm = async () => {
    const scheduledAt = new Date(campaign.scheduled_at).toLocaleString('ko-KR');
    if (!confirm(`캠페인을 Cron 자동 실행 대기로 설정할까요?\n\n${scheduledAt}에 Phase 1이 자동 실행됩니다.\n\n수동으로 즉시 실행하려면 [실행] 버튼을 사용하세요.`)) return;

    setRunning(true);
    setError('');
    try {
      const res = await fetch(`/api/campaigns/${campaign.id}/confirm`, { method: 'POST' });
      const data = await res.json();
      if (!data.success) throw new Error(data.error);
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : '상태 변경 실패');
    } finally {
      setRunning(false);
    }
  };

  const handleCancel = async () => {
    if (!confirm(`"${campaign.name}" 발송 예약을 취소할까요?\n\nMarketo Batch Smart Campaign은 API로 직접 취소할 수 없습니다.\n이 버튼을 누르면 캠페인이 'cancelling' 상태로 전환되고,\nMarketo에서 수동으로 SC를 비활성화한 뒤 "수동 취소 완료 → 초기화" 버튼으로 복구해야 합니다.`)) return;

    setRunning(true);
    setError('');
    try {
      const res = await fetch(`/api/campaigns/${campaign.id}/cancel`, { method: 'POST' });
      const data = await res.json();
      if (!data.success) throw new Error(data.error);
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : '취소 실패');
    } finally {
      setRunning(false);
    }
  };

  const handleResetToDraft = async () => {
    const scId = campaign.marketo_campaign_id;
    const warning = scId
      ? `Marketo에서 Smart Campaign(ID: ${scId})을 직접 비활성화했나요?\n\n아직 비활성화하지 않았다면 취소하고 Marketo에서 먼저 처리하세요.\n\n완료했다면 확인을 눌러 캠페인을 초안으로 초기화합니다.`
      : `Marketo에서 Email Program을 직접 unapprove했나요?\n\n아직 처리하지 않았다면 취소하고 Marketo에서 먼저 처리하세요.\n\n완료했다면 확인을 눌러 캠페인을 초안으로 초기화합니다.`;

    if (!confirm(warning)) return;

    setRunning(true);
    setError('');
    try {
      const res = await fetch(`/api/campaigns/${campaign.id}/reset-to-draft`, { method: 'POST' });
      const data = await res.json();
      if (!data.success) throw new Error(data.error);
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : '초기화 실패');
    } finally {
      setRunning(false);
    }
  };

  const handleDelete = async () => {
    if (!confirm('캠페인을 삭제할까요?')) return;
    await fetch(`/api/campaigns/${campaign.id}`, { method: 'DELETE' });
    router.push('/campaigns');
  };

  const isAwaitingApproval = campaign.status === 'awaiting_approval';

  return (
    <div className="flex flex-col items-end gap-2">
      {isAwaitingApproval && (
        <div className="flex gap-2">
          <Button loading={running} onClick={handleApprove}>
            <CheckCheck className="h-4 w-4" />
            발송 승인
          </Button>
          <Button variant="outline" loading={running} onClick={handleReject}>
            <RotateCcw className="h-4 w-4" />
            재검토
          </Button>
        </div>
      )}
      <div className="flex gap-2">
        {campaign.status === 'draft' && (
          <Button variant="outline" loading={running} onClick={handleConfirm}>
            <CalendarCheck className="h-4 w-4" />
            자동 실행 예약
          </Button>
        )}
        {canRun && !isAwaitingApproval && (
          <Button loading={running} onClick={handleRun}>
            <Play className="h-4 w-4" />
            즉시 실행
          </Button>
        )}
        {campaign.status === 'failed' && (
          <Button variant="outline" loading={running} onClick={handleRun}>
            <RefreshCw className="h-4 w-4" />
            재실행
          </Button>
        )}
        {(campaign.status === 'scheduled' || campaign.status === 'cancelling') && (
          <Button variant="outline" loading={running} onClick={handleCancel}>
            <XCircle className="h-4 w-4" />
            {campaign.status === 'cancelling' ? '취소 재시도' : '예약 취소'}
          </Button>
        )}
        {campaign.status === 'cancelling' && (
          <Button variant="danger" loading={running} onClick={handleResetToDraft}>
            <ShieldCheck className="h-4 w-4" />
            수동 취소 완료 → 초기화
          </Button>
        )}
        <Button variant="danger" onClick={handleDelete}>
          <Trash2 className="h-4 w-4" />
        </Button>
      </div>
      <Link
        href={`/campaigns/new?clone=${campaign.id}`}
        className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50 transition-colors font-medium"
      >
        <Copy className="h-3.5 w-3.5" />
        이 설정으로 새 발송 만들기
      </Link>
      {error && <p className="text-xs text-red-500">{error}</p>}
    </div>
  );
}
