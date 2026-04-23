'use client';

import { useState, useEffect, useCallback } from 'react';
import { JobLog } from '@/lib/types';
import { formatDate } from '@/lib/utils';
import { CheckCircle2, XCircle, Loader2, Clock } from 'lucide-react';

interface JobLogListProps {
  campaignId: string;
  initialLogs: JobLog[];
  isRunning: boolean;
}

const StatusIcon = ({ status }: { status: string }) => {
  if (status === 'done') return <CheckCircle2 className="h-4 w-4 text-green-500 shrink-0" />;
  if (status === 'error') return <XCircle className="h-4 w-4 text-red-500 shrink-0" />;
  if (status === 'running') return <Loader2 className="h-4 w-4 text-indigo-500 animate-spin shrink-0" />;
  return <Clock className="h-4 w-4 text-slate-300 shrink-0" />;
};

export function JobLogList({ campaignId, initialLogs, isRunning }: JobLogListProps) {
  const [logs, setLogs] = useState(initialLogs);
  const [polling, setPolling] = useState(isRunning);

  const fetchLogs = useCallback(async () => {
    try {
      const res = await fetch(`/api/campaigns/${campaignId}/logs`);
      const data = await res.json();
      if (data.success) {
        setLogs(data.data);
        const hasRunning = (data.data as JobLog[]).some((l) => l.status === 'running' || l.status === 'pending');
        if (!hasRunning) setPolling(false);
      }
    } catch { /* ignore */ }
  }, [campaignId]);

  useEffect(() => {
    if (!polling) return;
    const interval = setInterval(fetchLogs, 2000);
    return () => clearInterval(interval);
  }, [polling, fetchLogs]);

  if (logs.length === 0) {
    return (
      <div className="py-8 text-center text-sm text-slate-400">
        아직 실행 로그가 없습니다. 캠페인을 실행하면 여기에 표시됩니다.
      </div>
    );
  }

  return (
    <div className="divide-y divide-slate-50">
      {logs.map((log) => (
        <div key={log.id} className="flex items-start gap-3 py-3 px-1">
          <StatusIcon status={log.status} />
          <div className="flex-1 min-w-0">
            <p className="text-sm font-medium text-slate-700">{log.step}</p>
            {log.message && <p className="text-xs text-slate-400 mt-0.5">{log.message}</p>}
          </div>
          <p className="text-xs text-slate-300 shrink-0">{formatDate(log.created_at)}</p>
        </div>
      ))}
    </div>
  );
}
