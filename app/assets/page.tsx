import { getDb } from '@/db/sqlite';
import { AssetLibraryItem } from '@/lib/types';
import { AssetList } from '@/components/asset-list';

export default function AssetsPage() {
  const db = getDb();
  const assets = db
    .prepare('SELECT * FROM asset_library ORDER BY updated_at DESC')
    .all() as AssetLibraryItem[];

  return (
    <div className="p-8 space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-900">에셋 라이브러리</h1>
        <p className="text-sm text-slate-500 mt-1">이메일 발송에 사용할 이미지·텍스트 세트를 관리합니다.</p>
      </div>
      <AssetList initialAssets={assets} />
    </div>
  );
}
