'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { RecurringSegmentRow } from '@/lib/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';

// ────────────────────────────────────────────
// 타입
// ────────────────────────────────────────────

interface RecurringGroupDashboardProps {
  segments: RecurringSegmentRow[];
  assets: { id: string; name: string; emoji: string }[];
}

interface MiniFormState {
  assetId: string;
  rewardUrl: string;
  loading: boolean;
  step: string;   // 진행 단계 표시 ('캠페인 생성 중...' 등)
  error: string;
}

// ────────────────────────────────────────────
// 상수
// ────────────────────────────────────────────

const BLOCKING_STATUSES = [
  'extracting',
  'uploading',
  'preparing',
  'awaiting_approval',
  'scheduling',
  'scheduled',
  'cancelling',
];

// 0=일~6=토, 7=매일
const DAY_LABELS = ['일', '월', '화', '수', '목', '금', '토', '매일'];

// ────────────────────────────────────────────
// 유틸 함수
// ────────────────────────────────────────────

function getNextSendDate(dow: number): string {
  if (dow === 7) {
    // 매일 발송: 오늘 날짜 표시
    return new Date().toLocaleDateString('ko-KR', { month: 'long', day: 'numeric', weekday: 'short' }) + ' (매일)';
  }
  const today = new Date();
  let diff = dow - today.getDay();
  if (diff < 0) diff += 7;
  const next = new Date(today);
  next.setDate(today.getDate() + diff);
  return next.toLocaleDateString('ko-KR', { month: 'long', day: 'numeric', weekday: 'short' });
}

// ────────────────────────────────────────────
// 컴포넌트
// ────────────────────────────────────────────

export function RecurringGroupDashboard({ segments, assets }: RecurringGroupDashboardProps) {
  const [openForms, setOpenForms] = useState<Record<string, MiniFormState | undefined>>({});
  const router = useRouter();

  // 미니 폼 열기
  function openForm(segId: string) {
    setOpenForms((prev) => ({
      ...prev,
      [segId]: { assetId: '', rewardUrl: '', loading: false, step: '', error: '' },
    }));
  }

  // 미니 폼 닫기
  function closeForm(segId: string) {
    setOpenForms((prev) => {
      const next = { ...prev };
      delete next[segId];
      return next;
    });
  }

  // 캠페인 생성 + Phase 1 실행
  async function handleStart(seg: RecurringSegmentRow) {
    const form = openForms[seg.id];
    if (!form || !form.assetId || !form.rewardUrl.trim()) return;

    const setStep = (step: string) =>
      setOpenForms((prev) => ({ ...prev, [seg.id]: { ...prev[seg.id]!, loading: true, step, error: '' } }));

    setStep('캠페인 생성 중...');

    try {
      const dateStr = new Date().toISOString().slice(0, 10);
      const asset = assets.find((a) => a.id === form.assetId);
      const assetName = asset ? `${asset.emoji ? asset.emoji + ' ' : ''}${asset.name}` : '';

      // 1. 캠페인 생성
      const createRes = await fetch('/api/campaigns', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: `${seg.name} ${dateStr}`,
          segment_id: seg.id,
          segment_name: seg.name,
          asset_library_id: form.assetId,
          asset_name: assetName,
          reward_url: form.rewardUrl,
          scheduled_at: new Date().toISOString(),
          send_time: seg.recurring_send_time,
        }),
      });
      const createData = await createRes.json();
      if (!createData.success) throw new Error(createData.error);

      // 2. Phase 1 즉시 실행
      setStep('대상자 추출 및 Marketo 업로드 중...');
      const runRes = await fetch(`/api/campaigns/${createData.data.id}/run`, { method: 'POST' });
      const runData = await runRes.json();
      if (!runData.success) throw new Error(runData.error);

      // 3. 상세 페이지로 이동 (테스트 메일 수신 확인 후 승인)
      router.push(`/campaigns/${createData.data.id}`);
    } catch (err) {
      const currentForm = openForms[seg.id];
      if (currentForm) {
        setOpenForms((prev) => ({
          ...prev,
          [seg.id]: {
            ...currentForm,
            loading: false,
            step: '',
            error: err instanceof Error ? err.message : '오류가 발생했습니다.',
          },
        }));
      }
    }
  }

  const assetOptions = assets.map((a) => ({
    value: a.id,
    label: `${a.emoji ? a.emoji + ' ' : ''}${a.name}`,
  }));

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
      {segments.map((seg) => {
        const isBlocked =
          !!seg.latest_campaign_status &&
          BLOCKING_STATUSES.includes(seg.latest_campaign_status);
        const form = openForms[seg.id];

        return (
          <Card key={seg.id}>
            <CardContent className="flex flex-col gap-3">
              {/* 상단: 이름 + 요일·시간 */}
              <div className="flex items-start justify-between gap-2">
                <p className="text-sm font-semibold text-slate-800 leading-snug">{seg.name}</p>
                <p className="shrink-0 text-xs text-slate-400 mt-0.5">
                  {seg.send_day_of_week === 7
                    ? `매일 · ${seg.recurring_send_time} RTZ`
                    : `${DAY_LABELS[seg.send_day_of_week]}요일 · ${seg.recurring_send_time} RTZ`}
                </p>
              </div>

              {/* 다음 발송 */}
              <p className="text-xs text-slate-500">
                다음 발송: {getNextSendDate(seg.send_day_of_week)}
              </p>

              {/* 최근 캠페인 */}
              <div className="flex items-center gap-2 min-h-[1.5rem]">
                {seg.latest_campaign_name ? (
                  <>
                    <span className="text-xs text-slate-600 truncate">{seg.latest_campaign_name}</span>
                    {seg.latest_campaign_status && (
                      <StatusBadge status={seg.latest_campaign_status} />
                    )}
                  </>
                ) : (
                  <span className="text-xs text-slate-400">최근 캠페인 없음</span>
                )}
              </div>

              {/* 미니 폼 (인라인 확장) */}
              {form && (
                <div className="flex flex-col gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                  <Select
                    label="에셋 선택"
                    options={assetOptions}
                    placeholder="에셋을 선택하세요"
                    value={form.assetId}
                    onChange={(e) =>
                      setOpenForms((prev) => ({
                        ...prev,
                        [seg.id]: { ...form, assetId: e.target.value },
                      }))
                    }
                    disabled={form.loading}
                  />
                  <Input
                    label="보상 URL"
                    placeholder="https://..."
                    value={form.rewardUrl}
                    onChange={(e) =>
                      setOpenForms((prev) => ({
                        ...prev,
                        [seg.id]: { ...form, rewardUrl: e.target.value },
                      }))
                    }
                    disabled={form.loading}
                  />
                  {form.step && (
                    <p className="text-xs text-indigo-500 animate-pulse">{form.step}</p>
                  )}
                  {form.error && (
                    <p className="text-xs text-red-500">{form.error}</p>
                  )}
                  <div className="flex gap-2 justify-end pt-1">
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => closeForm(seg.id)}
                      disabled={form.loading}
                    >
                      취소
                    </Button>
                    <Button
                      variant="primary"
                      size="sm"
                      loading={form.loading}
                      disabled={!form.assetId || !form.rewardUrl.trim()}
                      onClick={() => handleStart(seg)}
                    >
                      시작
                    </Button>
                  </div>
                </div>
              )}

              {/* 하단 액션 */}
              {!form && (
                <div className="pt-1">
                  {isBlocked ? (
                    <Link
                      href={`/campaigns/${seg.latest_campaign_id}`}
                      className="text-sm font-medium text-indigo-600 hover:text-indigo-500 transition-colors"
                    >
                      진행 중 →
                    </Link>
                  ) : (
                    <Button
                      variant="primary"
                      size="sm"
                      onClick={() => openForm(seg.id)}
                      className="w-full"
                    >
                      이번 주 캠페인 시작
                    </Button>
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}
