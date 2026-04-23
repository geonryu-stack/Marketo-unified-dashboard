'use client';

import { SendGroup } from '@/lib/types';

interface GroupPanelProps {
  groups: SendGroup[];
  selectedId: string;
  onSelect: (id: string) => void;
}

export function GroupPanel({ groups, selectedId, onSelect }: GroupPanelProps) {
  return (
    <aside className="w-52 flex-shrink-0 bg-white border-r border-slate-200 flex flex-col overflow-y-auto">
      <div className="px-4 py-3 text-[11px] font-semibold text-slate-400 uppercase tracking-widest border-b border-slate-100">
        발송 그룹
      </div>
      {groups.map((group) => (
        <button
          key={group.id}
          onClick={() => onSelect(group.id)}
          className={[
            'text-left px-4 py-3 border-l-2 border-b border-slate-50 transition-all',
            selectedId === group.id
              ? 'border-l-indigo-500 bg-indigo-50'
              : 'border-l-transparent hover:bg-slate-50',
          ].join(' ')}
        >
          <div className={`text-sm font-semibold ${selectedId === group.id ? 'text-indigo-700' : 'text-slate-800'}`}>
            {group.name}
          </div>
          <div className="text-xs text-slate-400 mt-0.5">SC #{group.marketo_campaign_id}</div>
        </button>
      ))}
      <div className="flex-1" />
      <div className="mx-3 my-3 px-3 py-2 border border-dashed border-slate-200 rounded-lg text-center text-xs text-slate-400">
        그룹 추가는 DB에<br />직접 insert
      </div>
    </aside>
  );
}
