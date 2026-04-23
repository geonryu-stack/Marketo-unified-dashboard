'use client';

import { useState } from 'react';
import { DaySend, MarketoEmailItem, SendGroup } from '@/lib/types';
import { getWeekDates, getWeekStart } from '@/lib/utils';
import { DayRow } from './day-row';
import { ChevronLeft, ChevronRight, FlaskConical } from 'lucide-react';

interface WeekScheduleProps {
  group: SendGroup;
  initialWeekStart: string;
  initialSchedules: DaySend[];
  emails: MarketoEmailItem[];
}

export function WeekSchedule({ group, initialWeekStart, initialSchedules, emails }: WeekScheduleProps) {
  const [weekStart, setWeekStart] = useState(initialWeekStart);
  const [schedules, setSchedules] = useState<DaySend[]>(initialSchedules);
  const [loading, setLoading] = useState(false);
  const [testing, setTesting] = useState(false);
  const [scheduling, setScheduling] = useState(false);

  const today = new Date().toISOString().slice(0, 10);
  const currentWeekStart = getWeekStart();
  const weekDates = getWeekDates(weekStart);

  async function loadSchedules(ws: string) {
    setLoading(true);
    try {
      const res = await fetch(`/api/send-schedules?groupId=${group.id}&weekStart=${ws}`);
      const json = await res.json();
      if (json.success) setSchedules(json.data);
    } finally {
      setLoading(false);
    }
  }

  function changeWeek(dir: -1 | 1) {
    const d = new Date(weekStart + 'T00:00:00');
    d.setDate(d.getDate() + dir * 7);
    const newWs = d.toISOString().slice(0, 10);
    setWeekStart(newWs);
    loadSchedules(newWs);
  }

  function getScheduleForDate(date: string): DaySend | null {
    return schedules.find((s) => s.send_date === date) ?? null;
  }

  async function handleToggleOn(date: string) {
    const res = await fetch('/api/send-schedules', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        groupId: group.id,
        date,
        marketoEmailId: emails[0]?.id ?? 0,
        marketoEmailName: emails[0]?.name ?? '',
        sendTime: '10:00',
        timezone: 'RTZ',
      }),
    });
    const json = await res.json();
    if (json.success) {
      setSchedules((prev) => {
        const filtered = prev.filter((s) => s.send_date !== date);
        return [...filtered, json.data].sort((a, b) => a.send_date.localeCompare(b.send_date));
      });
    }
  }

  async function handleToggleOff(date: string) {
    await fetch(`/api/send-schedules?groupId=${group.id}&date=${date}`, { method: 'DELETE' });
    setSchedules((prev) => prev.filter((s) => s.send_date !== date));
  }

  async function handleSave(date: string, patch: Partial<DaySend>) {
    const existing = getScheduleForDate(date);
    if (!existing) return;
    const res = await fetch('/api/send-schedules', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        groupId: group.id,
        date,
        marketoEmailId: patch.marketo_email_id ?? existing.marketo_email_id,
        marketoEmailName: patch.marketo_email_name ?? existing.marketo_email_name,
        sendTime: patch.send_time ?? existing.send_time,
        timezone: patch.timezone ?? existing.timezone,
      }),
    });
    const json = await res.json();
    if (json.success) {
      setSchedules((prev) =>
        prev.map((s) => (s.send_date === date ? json.data : s))
      );
    }
  }

  async function handleBulkTest() {
    setTesting(true);
    try {
      const res = await fetch('/api/send-schedules/test', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ groupId: group.id, weekStart }),
      });
      const json = await res.json();
      if (json.success || res.status === 207) await loadSchedules(weekStart);
      if (!json.success) alert(`일부 테스트 발송 실패:\n${JSON.stringify(json.data, null, 2)}`);
    } finally {
      setTesting(false);
    }
  }

  async function handleBulkSchedule() {
    const testDone = schedules.filter((s) => weekDates.includes(s.send_date) && s.status === 'test_sent');
    if (testDone.length === 0) {
      alert('테스트 발송을 먼저 완료해 주세요.');
      return;
    }
    setScheduling(true);
    try {
      const res = await fetch('/api/send-schedules/schedule', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ groupId: group.id, weekStart }),
      });
      const json = await res.json();
      if (json.success || res.status === 207) await loadSchedules(weekStart);
      if (!json.success) alert(`일부 예약 실패:\n${JSON.stringify(json.data, null, 2)}`);
    } finally {
      setScheduling(false);
    }
  }

  const activeSchedules = schedules.filter((s) => weekDates.includes(s.send_date));
  const testDoneCount = activeSchedules.filter((s) => s.status === 'test_sent' || s.status === 'scheduled' || s.status === 'sent').length;
  const scheduledCount = activeSchedules.filter((s) => s.status === 'scheduled' || s.status === 'sent').length;

  const weekLabel = (() => {
    const start = new Date(weekStart + 'T00:00:00');
    const end = new Date(weekStart + 'T00:00:00');
    end.setDate(end.getDate() + 6);
    return `${start.getMonth() + 1}월 ${start.getDate()}일 ~ ${end.getMonth() + 1}월 ${end.getDate()}일`;
  })();

  return (
    <div className="flex flex-col flex-1 overflow-hidden">
      {/* Header */}
      <div className="flex items-center justify-between px-6 pt-5 pb-4">
        <div>
          <h2 className="text-base font-bold text-slate-900">{group.name} — 주간 발송 스케줄</h2>
          <p className="text-xs text-slate-500 mt-0.5">SC #{group.marketo_campaign_id} · 활성 {activeSchedules.length}일</p>
        </div>
        <div className="flex items-center gap-2">
          <div className="flex items-center gap-1 bg-white border border-slate-200 rounded-lg px-3 py-1.5 text-sm font-medium text-slate-700">
            <button onClick={() => changeWeek(-1)} className="p-0.5 hover:text-indigo-600 transition-colors">
              <ChevronLeft className="h-4 w-4" />
            </button>
            <span className="mx-2">{weekLabel}</span>
            <button onClick={() => changeWeek(1)} className="p-0.5 hover:text-indigo-600 transition-colors">
              <ChevronRight className="h-4 w-4" />
            </button>
          </div>
          <button
            onClick={handleBulkTest}
            disabled={testing || activeSchedules.length === 0}
            className="flex items-center gap-1.5 px-3 py-1.5 text-sm font-semibold rounded-lg
                       bg-emerald-50 text-emerald-700 border border-emerald-200
                       hover:bg-emerald-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            <FlaskConical className="h-4 w-4" />
            {testing ? '발송 중...' : '전체 테스트 발송'}
          </button>
        </div>
      </div>

      {/* Day rows */}
      <div className="flex-1 overflow-y-auto px-6 pb-4 space-y-2">
        {loading ? (
          <div className="py-16 text-center text-sm text-slate-400">로딩 중...</div>
        ) : (
          weekDates.map((date) => (
            <DayRow
              key={date}
              date={date}
              emails={emails}
              schedule={getScheduleForDate(date)}
              isPast={weekStart < currentWeekStart || date < today}
              onToggleOn={handleToggleOn}
              onToggleOff={handleToggleOff}
              onSave={handleSave}
            />
          ))
        )}

        {weekStart < currentWeekStart && (
          <p className="text-xs text-slate-400 text-center py-2">과거 주는 조회만 가능합니다.</p>
        )}
      </div>

      {/* Bottom action bar */}
      <div className="border-t border-slate-200 bg-white px-6 py-3 flex items-center justify-between">
        <div className="flex items-center gap-2 text-sm text-slate-600">
          <span className="font-semibold">{group.name}</span>
          <span className="text-slate-400">·</span>
          <span>활성 <strong>{activeSchedules.length}일</strong></span>
          <span className="text-slate-400">·</span>
          <span>테스트 완료 <strong>{testDoneCount}</strong> / {activeSchedules.length}</span>
          {scheduledCount > 0 && (
            <>
              <span className="text-slate-400">·</span>
              <span className="text-blue-600 font-semibold">📅 예약됨 {scheduledCount}일</span>
            </>
          )}
        </div>
        <div className="flex gap-2">
          <button
            onClick={() => { setSchedules([]); loadSchedules(weekStart); }}
            className="px-3 py-1.5 text-sm font-medium rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"
          >
            새로고침
          </button>
          <button
            onClick={handleBulkSchedule}
            disabled={scheduling || testDoneCount === 0}
            className="px-4 py-1.5 text-sm font-semibold rounded-lg bg-indigo-600 text-white
                       hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            {scheduling ? '예약 중...' : `🚀 ${group.name} 주간 예약하기`}
          </button>
        </div>
      </div>
    </div>
  );
}
