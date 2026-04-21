import { getDb } from '@/db/sqlite';
import { Segment, AssetLibraryItem, Campaign } from '@/lib/types';
import { CampaignWizard, DefaultValues } from '@/components/campaign-wizard';

type Props = { searchParams: Promise<{ clone?: string }> };

export default async function NewCampaignPage({ searchParams }: Props) {
  const { clone } = await searchParams;
  const db = getDb();

  const segments = db.prepare('SELECT id, name, last_count FROM segments ORDER BY name').all() as Pick<Segment, 'id' | 'name' | 'last_count'>[];
  const assets = db.prepare('SELECT id, name, emoji, subject, marketo_email_id FROM asset_library ORDER BY name').all() as Pick<AssetLibraryItem, 'id' | 'name' | 'emoji' | 'subject' | 'marketo_email_id'>[];

  let defaultValues: DefaultValues | undefined;
  if (clone) {
    const source = db.prepare('SELECT * FROM campaigns WHERE id = ?').get(clone) as Campaign | undefined;
    if (source) {
      defaultValues = {
        name: `${source.name} 복사본`,
        segmentId: source.segment_id,
        assetId: source.asset_library_id,
        sendTime: source.send_time || '10:00',
        rewardUrl: '',
      };
    }
  }

  return (
    <div className="p-8 max-w-2xl">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-900">새 캠페인</h1>
        <p className="text-sm text-slate-500 mt-1">세그먼트, 에셋, 발송 일정을 설정합니다.</p>
      </div>
      <CampaignWizard segments={segments} assets={assets} defaultValues={defaultValues} />
    </div>
  );
}
