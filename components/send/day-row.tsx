'use client';

import { DaySend, MarketoEmailItem } from '@/lib/types';
import { getDayLabel, isWeekend } from '@/lib/utils';

interface DayRowProps {
  date: string;
  emails: MarketoEmailItem[];
  schedule: DaySend | null;
  isPast: boolean;
  onToggleOn: (date: string) => void;
  onToggleOff: (date: string) => void;
  onFieldBlur: (date: string, field: keyof Pick<DaySend, 'marketo_email_id' | 'marketo_email_name' | 'send_time' | 'timezone'>, value: string | number) => void;
  onSave: (date: string, patch: Partial<DaySend>) => void;
}

export function DayRow({ date, emails, schedule, isPast, onToggleOn, onToggleOff, onSave }: DayRowProps) {
  const isOn = schedule !== null;
  const dayLabel = getDayLabel(date);
  const weekend = isWeekend(date);
  const isLocked = schedule?.status === 'scheduled' || schedule?.status === 'sent';

  const statusChip = (() => {
    if (!schedule) return null;
    if (schedule.status === 'scheduled') return <span className="chip chip-blue">📅 예약됨</span>;
    if (schedule.status === 'test_sent') return <span className="chip chip-green">✅ 테스트 완료</span>;
    if (schedule.status === 'failed')    return <span className="chip chip-red">❌ 오류</span>;
    return <span className="chip chip-yellow">⏳ 대기</span>;
  })();

  function handleToggle() {
    if (isPast || isLocked) return;
    if (isOn) {
      onToggleOff(date);
    } else {
      onToggleOn(date);
    }
  }

  function handleEmailChange(e: React.ChangeEvent<HTMLSelectElement>) {
    const emailId = parseInt(e.target.value, 10);
    const emailName = emails.find((em) => em.id === emailId)?.name ?? '';
    onSave(date, { marketo_email_id: emailId, marketo_email_name: emailName });
  }

  function handleTimeBlur(e: React.FocusEvent<HTMLInputElement>) {
    onSave(date, { send_time: e.target.value });
  }

  function handleTzClick(tz: 'RTZ' | 'KST') {
    onSave(date, { timezone: tz });
  }

  return (
    <div className={[
      'flex items-center gap-3 rounded-xl border px-4 py-3 transition-all',
      isOn && !isLocked ? 'border-indigo-200 bg-indigo-50/40' : 'border-slate-200 bg-white',
      !isOn ? 'opacity-50' : '',
    ].join(' ')}>

      {/* Toggle */}
      <label className="relative inline-flex items-center cursor-pointer flex-shrink-0">
        <input
          type="checkbox"
          className="sr-only peer"
          checked={isOn}
          disabled={isPast || isLocked}
          onChange={handleToggle}
        />
        <div className="w-10 h-6 bg-slate-200 peer-checked:bg-indigo-500 rounded-full
                        after:content-[''] after:absolute after:top-[3px] after:left-[3px]
                        after:bg-white after:rounded-full after:h-[18px] after:w-[18px]
                        after:transition-all peer-checked:after:translate-x-4
                        peer-disabled:opacity-40" />
      </label>

      {/* Day label */}
      <div className="w-12 flex-shrink-0">
        <div className={`text-sm font-bold ${weekend ? 'text-red-500' : 'text-slate-800'}`}>
          {dayLabel}
        </div>
        <div className="text-xs text-slate-400">{date.slice(5)}</div>
      </div>

      {/* Fields — disabled when OFF or locked */}
      <div className={`flex gap-2 flex-1 items-end ${!isOn || isLocked ? 'pointer-events-none opacity-40' : ''}`}>

        {/* 에셋 선택 */}
        <div className="flex flex-col flex-[2] min-w-0">
          <label className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide mb-1">에셋</label>
          <select
            className="h-8 px-2 text-sm border border-slate-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300 truncate"
            value={schedule?.marketo_email_id ?? ''}
            onChange={handleEmailChange}
            disabled={!isOn || isLocked}
          >
            <option value="">에셋 선택...</option>
            {emails.map((em) => (
              <option key={em.id} value={em.id}>{em.name}</option>
            ))}
          </select>
        </div>

        {/* 발송 시각 */}
        <div className="flex flex-col w-24">
          <label className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide mb-1">발송 시각</label>
          <input
            type="time"
            className="h-8 px-2 text-sm border border-slate-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-300"
            defaultValue={schedule?.send_time ?? '10:00'}
            key={`${date}-time-${schedule?.send_time}`}
            onBlur={handleTimeBlur}
            disabled={!isOn || isLocked}
          />
        </div>

        {/* RTZ / KST 토글 */}
        <div className="flex flex-col w-20">
          <label className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide mb-1">시간대</label>
          <div className="flex h-8 border border-slate-300 rounded-md overflow-hidden">
            {(['RTZ', 'KST'] as const).map((tz) => (
              <button
                key={tz}
                type="button"
                onClick={() => handleTzClick(tz)}
                disabled={!isOn || isLocked}
                className={[
                  'flex-1 text-xs font-bold transition-colors',
                  schedule?.timezone === tz
                    ? 'bg-indigo-500 text-white'
                    : 'bg-white text-slate-500 hover:bg-slate-50',
                ].join(' ')}
              >
                {tz}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Status chip */}
      <div className="w-24 flex justify-end flex-shrink-0">
        {statusChip}
      </div>
    </div>
  );
}
