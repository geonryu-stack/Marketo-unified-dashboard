'use client';

import { useState, useEffect, useCallback } from 'react';
import { Plus, Trash2, RefreshCw } from 'lucide-react';
import { Button } from './ui/button';
import { Select } from './ui/select';
import { Input } from './ui/input';
import { FilterCondition, FilterFieldDef, FilterOperator } from '@/lib/types';

interface SegmentBuilderProps {
  filters: FilterCondition[];
  onChange: (filters: FilterCondition[]) => void;
}

const ALL_OPERATORS: { value: FilterOperator; label: string }[] = [
  { value: '=', label: '같음 (=)' },
  { value: '!=', label: '다름 (≠)' },
  { value: '>', label: '초과 (>)' },
  { value: '>=', label: '이상 (≥)' },
  { value: '<', label: '미만 (<)' },
  { value: '<=', label: '이하 (≤)' },
  { value: 'IN', label: '포함 (IN)' },
  { value: 'NOT IN', label: '미포함 (NOT IN)' },
  { value: 'LIKE', label: '유사 (LIKE)' },
  { value: 'IS NULL', label: '비어 있음' },
  { value: 'IS NOT NULL', label: '비어 있지 않음' },
];

const OPERATORS_BY_TYPE: Record<string, FilterOperator[]> = {
  text:    ['=', '!=', 'LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL'],
  number:  ['=', '!=', '>', '>=', '<', '<=', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL'],
  select:  ['=', '!=', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL'],
  boolean: ['=', '!=', 'IS NULL', 'IS NOT NULL'],
};

function getOperatorsForType(type: string | undefined) {
  const allowed = OPERATORS_BY_TYPE[type ?? 'text'] ?? OPERATORS_BY_TYPE.text;
  return ALL_OPERATORS.filter((o) => allowed.includes(o.value));
}

const NO_VALUE_OPS: FilterOperator[] = ['IS NULL', 'IS NOT NULL'];

export function SegmentBuilder({ filters, onChange }: SegmentBuilderProps) {
  const [fieldDefs, setFieldDefs] = useState<FilterFieldDef[]>([]);
  const [preview, setPreview] = useState<number | null>(null);
  const [previewing, setPreviewing] = useState(false);

  useEffect(() => {
    fetch('/api/internal-db/fields')
      .then((r) => r.json())
      .then((res) => { if (res.success) setFieldDefs(res.data); })
      .catch(console.error);
  }, []);

  const fieldOptions = fieldDefs.map((f) => ({ value: f.field, label: f.label }));

  const addFilter = () => {
    const first = fieldDefs[0];
    if (!first) return;
    onChange([...filters, { field: first.field, label: first.label, operator: '=', value: '' }]);
  };

  const removeFilter = (idx: number) => {
    onChange(filters.filter((_, i) => i !== idx));
  };

  const updateFilter = (idx: number, patch: Partial<FilterCondition>) => {
    onChange(filters.map((f, i) => (i === idx ? { ...f, ...patch } : f)));
  };

  const handleFieldChange = (idx: number, field: string) => {
    const def = fieldDefs.find((d) => d.field === field);
    const allowed = OPERATORS_BY_TYPE[def?.type ?? 'text'] ?? OPERATORS_BY_TYPE.text;
    const currentOp = filters[idx]?.operator;
    const operator = allowed.includes(currentOp) ? currentOp : '=';
    updateFilter(idx, { field, label: def?.label ?? field, operator, value: '' });
  };

  const handlePreview = useCallback(async () => {
    setPreviewing(true);
    try {
      const res = await fetch('/api/internal-db/preview', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ filters }),
      });
      const data = await res.json();
      if (data.success) setPreview(data.data.count);
    } catch {
      setPreview(null);
    } finally {
      setPreviewing(false);
    }
  }, [filters]);

  return (
    <div className="space-y-3">
      {filters.length === 0 && (
        <p className="text-sm text-slate-400 italic">
          조건을 추가하면 전체 대상자에서 필터링됩니다.
        </p>
      )}

      {filters.map((filter, idx) => {
        const def = fieldDefs.find((d) => d.field === filter.field);
        const noValue = NO_VALUE_OPS.includes(filter.operator);
        const operatorOptions = getOperatorsForType(def?.type);

        return (
          <div key={idx} className="flex items-end gap-2 flex-wrap">
            {/* Field */}
            <Select
              className="w-44"
              value={filter.field}
              options={fieldOptions}
              onChange={(e) => handleFieldChange(idx, e.target.value)}
            />

            {/* Operator */}
            <Select
              className="w-44"
              value={filter.operator}
              options={operatorOptions}
              onChange={(e) => updateFilter(idx, { operator: e.target.value as FilterOperator })}
            />

            {/* Value */}
            {!noValue && (
              def?.type === 'boolean' ? (
                <Select
                  className="w-44"
                  value={filter.value}
                  placeholder="값 선택"
                  options={[
                    { value: 'true', label: '참 (true)' },
                    { value: 'false', label: '거짓 (false)' },
                  ]}
                  onChange={(e) => updateFilter(idx, { value: e.target.value })}
                />
              ) : def?.type === 'select' && def.options ? (
                <Select
                  className="w-44"
                  value={filter.value}
                  placeholder="값 선택"
                  options={def.options.map((o) => ({ value: o, label: o }))}
                  onChange={(e) => updateFilter(idx, { value: e.target.value })}
                />
              ) : (
                <Input
                  className="w-44"
                  placeholder={
                    filter.operator === 'IN' || filter.operator === 'NOT IN'
                      ? 'a, b, c (콤마 구분)'
                      : '값 입력'
                  }
                  value={filter.value}
                  type={def?.type === 'number' ? 'number' : 'text'}
                  onChange={(e) => updateFilter(idx, { value: e.target.value })}
                />
              )
            )}

            <Button
              variant="ghost"
              size="sm"
              onClick={() => removeFilter(idx)}
              className="text-red-500 hover:text-red-600 hover:bg-red-50"
            >
              <Trash2 className="h-4 w-4" />
            </Button>
          </div>
        );
      })}

      <div className="flex items-center gap-3 pt-1">
        <Button variant="outline" size="sm" onClick={addFilter} disabled={fieldDefs.length === 0}>
          <Plus className="h-4 w-4" />
          조건 추가
        </Button>

        {filters.length > 0 && (
          <Button variant="ghost" size="sm" loading={previewing} onClick={handlePreview}>
            <RefreshCw className="h-3.5 w-3.5" />
            대상자 미리보기
          </Button>
        )}

        {preview !== null && (
          <span className="text-sm font-semibold text-indigo-600">
            {preview.toLocaleString('ko-KR')}명 해당
          </span>
        )}
      </div>
    </div>
  );
}
