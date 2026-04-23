'use client';

import { useState, useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';
import { ChevronDown, Search, Loader2, AlertCircle, X } from 'lucide-react';

export interface MarketoItem {
  id: number;
  name: string;
  programName?: string;
}

interface MarketoSelectProps {
  label: string;
  hint?: string;
  endpoint: string;      // '/api/marketo/campaigns' | '/api/marketo/lists'
  value: string;         // 현재 선택된 ID (string)
  onChange: (id: string) => void;
  onSelectItem?: (item: MarketoItem | undefined) => void;  // 선택 항목 전체 정보 (자동 페어링용)
  placeholder?: string;
}

export function MarketoSelect({
  label, hint, endpoint, value, onChange, onSelectItem, placeholder = '검색하여 선택...',
}: MarketoSelectProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [items, setItems] = useState<MarketoItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [fetchError, setFetchError] = useState('');
  const containerRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);

  // 컴포넌트 마운트 시 즉시 데이터 패치 → 편집 시 선택된 항목 이름 표시 가능
  useEffect(() => {
    let cancelled = false;
    const load = async () => {
      setLoading(true);
      setFetchError('');
      try {
        const r = await fetch(endpoint);
        const d = await r.json();
        if (!cancelled) setItems(d.success ? (d.data as MarketoItem[]) : []);
        if (!cancelled && !d.success) setFetchError(d.error ?? 'Marketo 조회 실패');
      } catch (e) {
        if (!cancelled) setFetchError(String(e));
      } finally {
        if (!cancelled) setLoading(false);
      }
    };
    load();
    return () => { cancelled = true; };
  }, [endpoint]);

  // 외부 클릭 시 드롭다운 닫기
  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
        setQuery('');
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  // 드롭다운 열릴 때 검색창 포커스
  useEffect(() => {
    if (open) setTimeout(() => searchRef.current?.focus(), 50);
  }, [open]);

  const selected = items.find((it) => String(it.id) === value);

  const filtered = query.trim()
    ? items.filter((it) =>
        it.name.toLowerCase().includes(query.toLowerCase()) ||
        (it.programName ?? '').toLowerCase().includes(query.toLowerCase()) ||
        String(it.id).includes(query)
      )
    : items;

  const handleSelect = (item: MarketoItem) => {
    onChange(String(item.id));
    onSelectItem?.(item);
    setOpen(false);
    setQuery('');
  };

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    onChange('');
    onSelectItem?.(undefined);
  };

  return (
    <div className="relative flex flex-col gap-1" ref={containerRef}>
      {label && <label className="text-sm font-medium text-slate-700">{label}</label>}

      {/* 트리거 버튼 */}
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={cn(
          'relative flex items-center justify-between gap-2 w-full',
          'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-left',
          'focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500',
          open && 'ring-2 ring-indigo-500 border-indigo-500'
        )}
      >
        {loading ? (
          <span className="flex items-center gap-1.5 text-slate-400">
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
            불러오는 중...
          </span>
        ) : fetchError ? (
          <span className="flex items-center gap-1.5 text-red-400">
            <AlertCircle className="h-3.5 w-3.5" />
            조회 실패
          </span>
        ) : selected ? (
          <span className="flex items-center gap-2 min-w-0">
            <span className="font-medium text-slate-900 truncate">{selected.name}</span>
            {selected.programName && (
              <span className="text-xs text-slate-400 shrink-0 hidden sm:inline">{selected.programName}</span>
            )}
            <span className="text-xs bg-indigo-100 text-indigo-600 px-1.5 py-0.5 rounded shrink-0">
              ID: {selected.id}
            </span>
          </span>
        ) : value ? (
          // 목록에 없지만 ID가 있는 경우 (이전에 저장된 값)
          <span className="text-slate-500">ID: {value}</span>
        ) : (
          <span className="text-slate-400">{placeholder}</span>
        )}

        <span className="flex items-center gap-1 shrink-0">
          {value && (
            <span
              role="button"
              onClick={handleClear}
              className="p-0.5 rounded hover:bg-slate-100 text-slate-400 hover:text-slate-600"
            >
              <X className="h-3 w-3" />
            </span>
          )}
          <ChevronDown className={cn('h-4 w-4 text-slate-400 transition-transform', open && 'rotate-180')} />
        </span>
      </button>

      {/* 드롭다운 */}
      {open && (
        <div className="absolute z-50 mt-1 w-full min-w-[280px] max-w-lg rounded-lg border border-slate-200 bg-white shadow-lg"
          style={{ top: '100%', left: 0 }}>
          {/* 검색 입력 */}
          <div className="flex items-center gap-2 px-3 py-2 border-b border-slate-100">
            <Search className="h-3.5 w-3.5 text-slate-400 shrink-0" />
            <input
              ref={searchRef}
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="이름 또는 ID 검색..."
              className="flex-1 text-sm outline-none placeholder:text-slate-400"
            />
          </div>

          {/* 결과 목록 */}
          <ul className="max-h-56 overflow-y-auto py-1">
            {filtered.length === 0 ? (
              <li className="px-3 py-2 text-xs text-slate-400 text-center">
                {query ? '검색 결과 없음' : '항목 없음'}
              </li>
            ) : (
              filtered.map((item) => (
                <li key={item.id}>
                  <button
                    type="button"
                    onClick={() => handleSelect(item)}
                    className={cn(
                      'w-full flex items-center justify-between gap-3 px-3 py-2 text-sm text-left',
                      'hover:bg-indigo-50 transition-colors',
                      String(item.id) === value && 'bg-indigo-50 text-indigo-700'
                    )}
                  >
                    <span className="flex flex-col min-w-0">
                      <span className="font-medium text-slate-900 truncate">{item.name}</span>
                      {item.programName && (
                        <span className="text-xs text-slate-400 truncate">{item.programName}</span>
                      )}
                    </span>
                    <span className="text-xs bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded shrink-0">
                      {item.id}
                    </span>
                  </button>
                </li>
              ))
            )}
          </ul>
        </div>
      )}

      {hint && <p className="text-xs text-slate-400">{hint}</p>}
      {fetchError && <p className="text-xs text-red-400">{fetchError}</p>}
    </div>
  );
}
