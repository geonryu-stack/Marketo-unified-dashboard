import { Badge } from './badge';
import { STATUS_LABELS, STATUS_COLORS } from '@/lib/utils';
import { CampaignStatus } from '@/lib/types';

const variantMap: Record<string, 'default' | 'success' | 'warning' | 'error' | 'info'> = {
  draft: 'default',
  confirmed: 'info',
  extracting: 'warning',
  uploading: 'warning',
  preparing: 'warning',
  awaiting_approval: 'warning',
  scheduling: 'warning',
  scheduled: 'info',
  cancelling: 'default',
  sent: 'success',
  failed: 'error',
};

export function StatusBadge({ status }: { status: CampaignStatus | string }) {
  return (
    <Badge variant={variantMap[status] ?? 'default'}>
      {STATUS_LABELS[status] ?? status}
    </Badge>
  );
}
