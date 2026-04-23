import { getDb } from '@/db/sqlite';
import { Segment } from '@/lib/types';
import { formatDate, formatNumber } from '@/lib/utils';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import Link from 'next/link';
import { Plus, Users, ChevronRight } from 'lucide-react';

export default function SegmentsPage() {
  const db = getDb();
  const segments = db.prepare('SELECT * FROM segments ORDER BY updated_at DESC').all() as Segment[];

  return (
    <div className="p-8 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">세그먼트</h1>
          <p className="text-sm text-slate-500 mt-1">사내 DB 기반 대상자 그룹 관리</p>
        </div>
        <Link
          href="/segments/new"
          className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-sm font-semibold text-white transition-colors"
        >
          <Plus className="h-4 w-4" />
          새 세그먼트
        </Link>
      </div>

      {segments.length === 0 ? (
        <Card>
          <CardContent className="py-16 text-center">
            <Users className="h-10 w-10 text-slate-300 mx-auto mb-3" />
            <p className="text-slate-500">아직 세그먼트가 없습니다.</p>
            <p className="text-sm text-slate-400 mt-1">
              <Link href="/segments/new" className="text-indigo-600 hover:underline">새 세그먼트를 만들어</Link> 대상자를 정의하세요.
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-3">
          {segments.map((seg) => {
            const filters =
              typeof seg.filters === 'string' ? JSON.parse(seg.filters) : seg.filters;
            return (
              <Link key={seg.id} href={`/segments/${seg.id}`}>
                <Card className="hover:border-indigo-200 hover:shadow-md transition-all cursor-pointer">
                  <CardContent className="py-4">
                    <div className="flex items-center justify-between">
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-3">
                          <div className="flex items-center justify-center w-9 h-9 rounded-lg bg-indigo-50">
                            <Users className="h-4 w-4 text-indigo-600" />
                          </div>
                          <div>
                            <p className="font-semibold text-slate-800">{seg.name}</p>
                            {seg.description && (
                              <p className="text-xs text-slate-400 mt-0.5">{seg.description}</p>
                            )}
                          </div>
                        </div>
                      </div>
                      <div className="flex items-center gap-6 text-sm shrink-0">
                        <div className="text-center">
                          <p className="text-xs text-slate-400">조건</p>
                          <p className="font-semibold text-slate-700">{filters.length}개</p>
                        </div>
                        {seg.last_count !== null && (
                          <div className="text-center">
                            <p className="text-xs text-slate-400">마지막 추출</p>
                            <p className="font-semibold text-indigo-600">{formatNumber(seg.last_count)}명</p>
                          </div>
                        )}
                        {seg.last_extracted_at && (
                          <div className="text-center hidden lg:block">
                            <p className="text-xs text-slate-400">추출일시</p>
                            <p className="text-slate-600 text-xs">{formatDate(seg.last_extracted_at)}</p>
                          </div>
                        )}
                        <ChevronRight className="h-4 w-4 text-slate-300" />
                      </div>
                    </div>
                  </CardContent>
                </Card>
              </Link>
            );
          })}
        </div>
      )}
    </div>
  );
}
