'use client';

import { useState, useEffect, useCallback } from 'react';
import { SendGroup, DaySend, MarketoEmailItem } from '@/lib/types';
import { getWeekStart } from '@/lib/utils';
import { GroupPanel } from './group-panel';
import { WeekSchedule } from './week-schedule';

interface SendPageClientProps {
  initialGroups: SendGroup[];
}

export function SendPageClient({ initialGroups }: SendPageClientProps) {
  const [selectedGroupId, setSelectedGroupId] = useState(initialGroups[0]?.id ?? '');
  const [weekStart] = useState(getWeekStart());
  const [schedules, setSchedules] = useState<DaySend[]>([]);
  const [emails, setEmails] = useState<MarketoEmailItem[]>([]);
  const [emailsLoading, setEmailsLoading] = useState(true);
  const [emailsError, setEmailsError] = useState<string | null>(null);

  const selectedGroup = initialGroups.find((g) => g.id === selectedGroupId) ?? initialGroups[0];

  const loadEmails = useCallback(() => {
    setEmailsLoading(true);
    setEmailsError(null);
    fetch('/api/marketo/emails')
      .then((r) => r.json())
      .then((json) => {
        if (json.success) setEmails(json.data);
        else setEmailsError(json.error ?? 'Marketo 에셋 목록 로드 실패');
      })
      .catch((e: Error) => setEmailsError(e.message))
      .finally(() => setEmailsLoading(false));
  }, []);

  // Marketo 이메일 목록 1회 로드
  useEffect(() => { loadEmails(); }, [loadEmails]);

  // 그룹/주 변경 시 스케줄 로드
  const loadSchedules = useCallback(async (groupId: string, ws: string) => {
    const res = await fetch(`/api/send-schedules?groupId=${groupId}&weekStart=${ws}`);
    const json = await res.json();
    if (json.success) setSchedules(json.data);
  }, []);

  useEffect(() => {
    if (selectedGroupId) loadSchedules(selectedGroupId, weekStart);
  }, [selectedGroupId, weekStart, loadSchedules]);

  if (!selectedGroup) {
    return <div className="flex-1 flex items-center justify-center text-slate-400">그룹이 없습니다.</div>;
  }

  return (
    <div className="flex flex-1 overflow-hidden">
      <GroupPanel
        groups={initialGroups}
        selectedId={selectedGroupId}
        onSelect={(id) => {
          setSelectedGroupId(id);
          setSchedules([]);
        }}
      />
      {emailsLoading ? (
        <div className="flex-1 flex items-center justify-center text-slate-400 text-sm">
          Marketo 에셋 목록 로딩 중...
        </div>
      ) : emailsError ? (
        <div className="flex-1 flex flex-col items-center justify-center gap-2 text-sm px-8 text-center">
          <p className="text-red-500 font-medium">Marketo 에셋 목록 로드 실패</p>
          <p className="text-slate-400 text-xs">{emailsError}</p>
          <button
            onClick={loadEmails}
            className="mt-1 px-3 py-1.5 text-xs rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-600"
          >
            다시 시도
          </button>
        </div>
      ) : (
        <WeekSchedule
          key={`${selectedGroupId}-${weekStart}`}
          group={selectedGroup}
          initialWeekStart={weekStart}
          initialSchedules={schedules}
          emails={emails}
        />
      )}
    </div>
  );
}
