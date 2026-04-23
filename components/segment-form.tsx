'use client';

import { useState, useCallback } from 'react';
import { useRouter } from 'next/navigation';
import { Input } from './ui/input';
import { Select } from './ui/select';
import { Textarea } from './ui/textarea';
import { Button } from './ui/button';
import { SegmentBuilder } from './segment-builder';
import { Card, CardContent } from './ui/card';
import { MarketoSelect, MarketoItem } from './ui/marketo-select';
import { FilterCondition, Segment } from '@/lib/types';
import { cn } from '@/lib/utils';

interface SegmentFormProps {
  initialData?: Partial<Segment> & { filters: FilterCondition[] };
}

export function SegmentForm({ initialData }: SegmentFormProps) {
  const router = useRouter();
  const [name, setName] = useState(initialData?.name ?? '');
  const [description, setDescription] = useState(initialData?.description ?? '');
  const [filters, setFilters] = useState<FilterCondition[]>(initialData?.filters ?? []);
  const [marketoProgramId, setMarketoProgramId] = useState(initialData?.marketo_program_id ?? '');
  const [marketoAudienceListId, setMarketoAudienceListId] = useState(initialData?.marketo_audience_list_id ?? '');
  const [marketoEmailProgramId, setMarketoEmailProgramId] = useState(initialData?.marketo_email_program_id ?? '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [isRecurring, setIsRecurring] = useState<boolean>(!!(initialData?.is_recurring));
  const [sendDayOfWeek, setSendDayOfWeek] = useState<number>(initialData?.send_day_of_week ?? 1);
  const [recurringTime, setRecurringTime] = useState<string>(initialData?.recurring_send_time ?? '10:00');
  const [pairHint, setPairHint] = useState<{ ok: boolean; msg: string } | null>(null);

  const isEdit = !!initialData?.id;

  // 캠페인 선택 시 네이밍 컨벤션으로 매칭 Static List 자동 세팅
  // ActiveA_Autosend 선택 → ActiveA_Autosend_Audience 자동 채움
  // 리스트가 이미 선택된 경우 덮어쓰지 않음 (수동 선택 존중)
  const handleCampaignSelect = useCallback((item: MarketoItem | undefined) => {
    if (!item) return;
    if (marketoAudienceListId) return; // 이미 리스트 선택됨 — 유지
    const CAMPAIGN_SUFFIX = '_autosend';
    const LIST_SUFFIX = '_autosend_audience';
    if (!item.name.toLowerCase().endsWith(CAMPAIGN_SUFFIX)) return;
    const base = item.name.slice(0, -CAMPAIGN_SUFFIX.length);
    fetch('/api/marketo/lists')
      .then((r) => r.json())
      .then((d) => {
        if (!d.success) return;
        const match = (d.data as MarketoItem[]).find(
          (l) => l.name.toLowerCase() === `${base.toLowerCase()}${LIST_SUFFIX}`
        );
        if (match) {
          setMarketoAudienceListId(String(match.id));
          setPairHint({ ok: true, msg: `✓ 매칭 리스트 자동 설정: ${match.name}` });
        } else {
          setPairHint({ ok: false, msg: '매칭 리스트를 찾지 못했습니다. Audience Static List를 직접 선택해주세요.' });
        }
      })
      .catch(() => {
        setPairHint({ ok: false, msg: 'Audience Static List를 직접 선택해주세요.' });
      });
  }, [marketoAudienceListId]);

  const handleSave = async () => {
    if (!name.trim()) { setError('세그먼트 이름을 입력해주세요.'); return; }
    setSaving(true);
    setError('');

    try {
      const url = isEdit ? `/api/segments/${initialData.id}` : '/api/segments';
      const method = isEdit ? 'PUT' : 'POST';
      const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name,
          description,
          filters,
          marketo_program_id: marketoProgramId,
          marketo_audience_list_id: marketoAudienceListId,
          marketo_email_program_id: marketoEmailProgramId,
          is_recurring: isRecurring ? 1 : 0,
          send_day_of_week: sendDayOfWeek,
          recurring_send_time: recurringTime,
        }),
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.error);
      router.push('/segments');
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : '저장 실패');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!confirm('세그먼트를 삭제할까요?')) return;
    await fetch(`/api/segments/${initialData!.id}`, { method: 'DELETE' });
    router.push('/segments');
    router.refresh();
  };

  return (
    <div className="space-y-5">
      <Card>
        <CardContent className="space-y-4 py-5">
          <Input
            label="세그먼트 이름"
            placeholder="예: 30일 미접속 한국 유저"
            value={name}
            onChange={(e) => setName(e.target.value)}
            error={error && !name ? error : undefined}
          />
          <Textarea
            label="설명 (선택)"
            placeholder="이 세그먼트의 목적이나 기준을 간단히 메모하세요."
            rows={2}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
          />
          <MarketoSelect
            label="Marketo Campaign"
            endpoint="/api/marketo/campaigns"
            value={marketoProgramId}
            onChange={setMarketoProgramId}
            onSelectItem={handleCampaignSelect}
            hint="발송 예약에 사용되는 Batch Smart Campaign을 이름으로 검색하여 선택하세요."
          />
          <MarketoSelect
            label="Audience Static List"
            endpoint="/api/marketo/lists"
            value={marketoAudienceListId}
            onChange={(id) => { setMarketoAudienceListId(id); setPairHint(null); }}
            hint="Smart Campaign Smart List의 'Member of List' 필터에 연결된 고정 Static List. 매 발송 시 멤버를 갱신하여 재사용합니다."
          />
          {pairHint && (
            <p className={`text-xs ${pairHint.ok ? 'text-indigo-500' : 'text-amber-500'}`}>
              {pairHint.msg}
            </p>
          )}
          <Input
            label="Email Program ID"
            placeholder="예: 713"
            value={marketoEmailProgramId}
            onChange={(e) => setMarketoEmailProgramId(e.target.value)}
            hint="Marketing Activities의 Email Program 숫자 ID. RTZ 발송 예약 및 My Token 주입에 사용됩니다."
          />
        </CardContent>
      </Card>

      {/* 반복 발송 그룹 */}
      <Card>
        <CardContent className="space-y-4 py-5">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm font-semibold text-slate-700">반복 발송 그룹</p>
              <p className="text-xs text-slate-400 mt-0.5">
                ON으로 설정하면 메인 대시보드에 이번 주 캠페인 시작 버튼이 표시됩니다.
              </p>
            </div>
            <button
              type="button"
              onClick={() => setIsRecurring(!isRecurring)}
              className={cn(
                'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500',
                isRecurring ? 'bg-indigo-600' : 'bg-slate-200'
              )}
              role="switch"
              aria-checked={isRecurring}
            >
              <span
                className={cn(
                  'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
                  isRecurring ? 'translate-x-6' : 'translate-x-1'
                )}
              />
            </button>
          </div>

          {isRecurring && (
            <div className="grid grid-cols-2 gap-4 pt-2 border-t border-slate-100">
              <Select
                label="발송 요일"
                value={String(sendDayOfWeek)}
                options={[
                  { value: '7', label: '매일 (주 7일)' },
                  { value: '1', label: '월요일' },
                  { value: '2', label: '화요일' },
                  { value: '3', label: '수요일' },
                  { value: '4', label: '목요일' },
                  { value: '5', label: '금요일' },
                  { value: '6', label: '토요일' },
                  { value: '0', label: '일요일' },
                ]}
                onChange={(e) => setSendDayOfWeek(Number(e.target.value))}
              />
              <Input
                label="RTZ 발송 시각"
                placeholder="예: 10:00"
                value={recurringTime}
                onChange={(e) => setRecurringTime(e.target.value)}
                hint="수신자 현지 시각 기준 (HH:MM)"
              />
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardContent className="py-5">
          <p className="text-sm font-semibold text-slate-700 mb-3">필터 조건</p>
          <SegmentBuilder filters={filters} onChange={setFilters} />
        </CardContent>
      </Card>

      {error && <p className="text-sm text-red-500">{error}</p>}

      <div className="flex items-center justify-between">
        <Button variant="secondary" onClick={() => router.back()}>취소</Button>
        <div className="flex gap-2">
          {isEdit && (
            <Button variant="danger" onClick={handleDelete}>삭제</Button>
          )}
          <Button loading={saving} onClick={handleSave}>
            {isEdit ? '저장' : '세그먼트 생성'}
          </Button>
        </div>
      </div>
    </div>
  );
}
