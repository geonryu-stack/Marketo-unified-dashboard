'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { Card, CardContent } from './ui/card';
import { Input } from './ui/input';
import { Select } from './ui/select';
import { Button } from './ui/button';
import { formatNumber, toISOLocal } from '@/lib/utils';
import { Segment, AssetLibraryItem } from '@/lib/types';
import { CheckCircle2, AlertCircle } from 'lucide-react';

type SegmentOption = Pick<Segment, 'id' | 'name' | 'last_count'>;
type AssetOption = Pick<AssetLibraryItem, 'id' | 'name' | 'emoji' | 'subject' | 'marketo_email_id'>;

export interface DefaultValues {
  name?: string;
  segmentId?: string;
  assetId?: string;
  sendTime?: string;
  rewardUrl?: string;
}

interface CampaignWizardProps {
  segments: SegmentOption[];
  assets: AssetOption[];
  defaultValues?: DefaultValues;
}

export function CampaignWizard({ segments, assets, defaultValues }: CampaignWizardProps) {
  const router = useRouter();

  const [name, setName] = useState(defaultValues?.name ?? '');
  const [segmentId, setSegmentId] = useState(defaultValues?.segmentId ?? '');
  const [assetId, setAssetId] = useState(defaultValues?.assetId ?? '');
  const [rewardUrl, setRewardUrl] = useState(defaultValues?.rewardUrl ?? '');
  const [scheduledAt, setScheduledAt] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    return toISOLocal(d);
  });
  const [sendTime, setSendTime] = useState(defaultValues?.sendTime ?? '10:00');

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const selectedSeg = segments.find((s) => s.id === segmentId);
  const selectedAsset = assets.find((a) => a.id === assetId);

  const segmentOptions = segments.map((s) => ({
    value: s.id,
    label: `${s.name}${s.last_count != null ? ` (${formatNumber(s.last_count)}명)` : ''}`,
  }));

  const assetOptions = assets.map((a) => ({
    value: a.id,
    label: `${a.emoji ? a.emoji + ' ' : ''}${a.name}`,
  }));

  // CONSTRAINT-07 체크리스트
  const checks = [
    { label: '캠페인 이름', ok: !!name.trim() },
    { label: '발송 세그먼트 선택', ok: !!segmentId && !!selectedSeg },
    { label: '에셋 선택', ok: !!assetId && !!selectedAsset },
    { label: '보상 URL 입력', ok: !!rewardUrl.trim() },
    { label: '예약 일시 설정', ok: !!scheduledAt },
    { label: 'RTZ 발송 시각 설정', ok: !!sendTime.trim() },
  ];
  const allRequired = checks.every((c) => c.ok);

  const handleCreate = async () => {
    if (!allRequired) { setError('필수 항목을 모두 입력해주세요.'); return; }
    setSaving(true);
    setError('');

    try {
      const res = await fetch('/api/campaigns', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name,
          segment_id: segmentId,
          segment_name: selectedSeg?.name ?? '',
          asset_library_id: assetId,
          asset_name: selectedAsset ? `${selectedAsset.emoji ? selectedAsset.emoji + ' ' : ''}${selectedAsset.name}` : '',
          reward_url: rewardUrl,
          scheduled_at: new Date(scheduledAt).toISOString(),
          send_time: sendTime,
        }),
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.error);
      router.push(`/campaigns/${data.data.id}`);
    } catch (err) {
      setError(err instanceof Error ? err.message : '생성 실패');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-5">
      {/* 기본 정보 */}
      <Card>
        <CardContent className="py-5 space-y-4">
          <p className="text-sm font-semibold text-slate-700">기본 정보</p>
          <Input
            label="캠페인 이름 *"
            placeholder="예: 4월 복귀 유저 프로모션"
            value={name}
            onChange={(e) => setName(e.target.value)}
          />
        </CardContent>
      </Card>

      {/* 발송 대상자 */}
      <Card>
        <CardContent className="py-5 space-y-4">
          <p className="text-sm font-semibold text-slate-700">발송 대상자</p>
          {segments.length === 0 ? (
            <p className="text-sm text-red-500">
              세그먼트가 없습니다.{' '}
              <Link href="/segments/new" className="text-indigo-600 underline">먼저 생성하세요.</Link>
            </p>
          ) : (
            <Select
              label="세그먼트 *"
              placeholder="세그먼트 선택"
              value={segmentId}
              options={segmentOptions}
              onChange={(e) => setSegmentId(e.target.value)}
            />
          )}
          {selectedSeg?.last_count != null && (
            <p className="text-sm text-indigo-600">
              마지막 추출 기준 <strong>{formatNumber(selectedSeg.last_count)}명</strong> 대상
            </p>
          )}
        </CardContent>
      </Card>

      {/* 에셋 */}
      <Card>
        <CardContent className="py-5 space-y-4">
          <p className="text-sm font-semibold text-slate-700">이메일 에셋</p>
          {assets.length === 0 ? (
            <p className="text-sm text-red-500">
              에셋이 없습니다.{' '}
              <a href="/assets" className="text-indigo-600 underline">먼저 등록하세요.</a>
            </p>
          ) : (
            <Select
              label="에셋 *"
              placeholder="에셋 선택"
              value={assetId}
              options={assetOptions}
              onChange={(e) => setAssetId(e.target.value)}
            />
          )}
          {selectedAsset && (
            <div className="rounded-lg bg-slate-50 px-4 py-3 text-sm space-y-1">
              <p className="font-medium text-slate-700">{selectedAsset.emoji} {selectedAsset.name}</p>
              {selectedAsset.subject && <p className="text-slate-500">제목: {selectedAsset.subject}</p>}
              {selectedAsset.marketo_email_id && (
                <p className="text-xs text-indigo-500">Marketo Clone 소스: {selectedAsset.marketo_email_id}</p>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      {/* 보상 URL — CONSTRAINT-02 */}
      <Card className="border-amber-200">
        <CardContent className="py-5 space-y-3">
          <div>
            <p className="text-sm font-semibold text-slate-700">보상 URL (수동 입력 필수)</p>
            <p className="text-xs text-amber-600 mt-0.5">
              CONSTRAINT-02: 인앱 보상 URL은 시스템이 자동 생성할 수 없습니다. 직접 발행 후 입력하세요.
            </p>
          </div>
          <Input
            label="보상 URL *"
            placeholder="https://... 또는 deeplink://..."
            value={rewardUrl}
            onChange={(e) => setRewardUrl(e.target.value)}
          />
        </CardContent>
      </Card>

      {/* 발송 예약 */}
      <Card>
        <CardContent className="py-5 space-y-4">
          <p className="text-sm font-semibold text-slate-700">발송 예약</p>
          <Input
            label="Phase 1 자동 실행 일시 *"
            type="datetime-local"
            value={scheduledAt}
            onChange={(e) => setScheduledAt(e.target.value)}
            hint="이 시각이 되면 Cron이 자동으로 대상자 추출 + 토큰 설정 + 테스트 메일 발송을 실행합니다."
          />
          <Input
            label="RTZ 발송 시각 *"
            placeholder="예: 10:00"
            value={sendTime}
            onChange={(e) => setSendTime(e.target.value)}
            hint="수신자 현지 시간 기준으로 이메일이 발송됩니다. 형식: HH:MM (예: 10:00, 14:30)"
          />
        </CardContent>
      </Card>

      {/* CONSTRAINT-07 체크리스트 */}
      <Card className="border-slate-200">
        <CardContent className="py-4">
          <p className="text-xs font-semibold text-slate-500 mb-3">발송 전 확인 체크리스트</p>
          <ul className="space-y-1.5">
            {checks.map((c) => (
              <li key={c.label} className="flex items-center gap-2 text-sm">
                {c.ok ? (
                  <CheckCircle2 className="h-4 w-4 text-green-500 shrink-0" />
                ) : (
                  <AlertCircle className="h-4 w-4 text-slate-300 shrink-0" />
                )}
                <span className={c.ok ? 'text-slate-700' : 'text-slate-400'}>{c.label}</span>
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>

      {error && <p className="text-sm text-red-500">{error}</p>}

      <div className="flex justify-between">
        <Button variant="secondary" onClick={() => router.back()}>취소</Button>
        <Button loading={saving} disabled={!allRequired} onClick={handleCreate}>
          캠페인 생성
        </Button>
      </div>
    </div>
  );
}
