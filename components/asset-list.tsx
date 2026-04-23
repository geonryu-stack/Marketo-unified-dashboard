'use client';

import { useState } from 'react';
import { AssetLibraryItem } from '@/lib/types';
import { Card, CardContent } from './ui/card';
import { Button } from './ui/button';
import { Input } from './ui/input';
import { Textarea } from './ui/textarea';
import { formatDate } from '@/lib/utils';
import { Plus, Pencil, Trash2, Image as ImageIcon, X } from 'lucide-react';

interface AssetListProps {
  initialAssets: AssetLibraryItem[];
}

// 폼에서 실제로 사용하는 필드만 포함
// (marketo_folder_id, reward_url_placeholder, send_mode, marketo_program_id 제거 —
//  clone 복제 기능이 현재 미구현이고 해당 Marketo 계정에서 My Token API 대신
//  SC inline token 주입 방식을 사용하므로 이 필드들은 불필요함)
type AssetFormState = {
  name: string;
  image_url: string;
  subject: string;
  emoji: string;
  preheader: string;
  body_text: string;
  tags: string;
  marketo_email_id: string | null;
  marketo_token_image: string;
  marketo_token_subject: string;
  marketo_token_preheader: string;
  marketo_token_body: string;
  marketo_token_emoji: string;
  marketo_token_reward_url: string;
};

const EMPTY: AssetFormState = {
  name: '',
  image_url: '',
  subject: '',
  emoji: '',
  preheader: '',
  body_text: '',
  tags: '',
  marketo_email_id: null,
  marketo_token_image: '',
  marketo_token_subject: '',
  marketo_token_preheader: '',
  marketo_token_body: '',
  marketo_token_emoji: '',
  marketo_token_reward_url: '',
};

export function AssetList({ initialAssets }: AssetListProps) {
  const [assets, setAssets] = useState(initialAssets);
  const [editing, setEditing] = useState<AssetLibraryItem | null>(null);
  const [isNew, setIsNew] = useState(false);
  const [form, setForm] = useState<AssetFormState>(EMPTY);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const openNew = () => {
    setForm(EMPTY);
    setEditing(null);
    setIsNew(true);
    setError('');
  };

  const openEdit = (asset: AssetLibraryItem) => {
    setForm({
      name: asset.name,
      image_url: asset.image_url,
      subject: asset.subject,
      emoji: asset.emoji,
      preheader: asset.preheader,
      body_text: asset.body_text,
      tags: asset.tags,
      marketo_email_id: asset.marketo_email_id,
      marketo_token_image: asset.marketo_token_image ?? '',
      marketo_token_subject: asset.marketo_token_subject ?? '',
      marketo_token_preheader: asset.marketo_token_preheader ?? '',
      marketo_token_body: asset.marketo_token_body ?? '',
      marketo_token_emoji: asset.marketo_token_emoji ?? '',
      marketo_token_reward_url: asset.marketo_token_reward_url ?? '',
    });
    setEditing(asset);
    setIsNew(false);
    setError('');
  };

  const closeForm = () => { setIsNew(false); setEditing(null); };

  const handleSave = async () => {
    if (!form.name.trim()) { setError('이름을 입력해주세요.'); return; }
    setSaving(true);
    setError('');
    try {
      const url = editing ? `/api/assets/${editing.id}` : '/api/assets';
      const method = editing ? 'PUT' : 'POST';
      const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(form),
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.error);

      if (editing) {
        setAssets((prev) => prev.map((a) => (a.id === editing.id ? data.data : a)));
      } else {
        setAssets((prev) => [data.data, ...prev]);
      }
      closeForm();
    } catch (err) {
      setError(err instanceof Error ? err.message : '저장 실패');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (id: string) => {
    if (!confirm('에셋을 삭제할까요?')) return;
    await fetch(`/api/assets/${id}`, { method: 'DELETE' });
    setAssets((prev) => prev.filter((a) => a.id !== id));
    if (editing?.id === id) closeForm();
  };

  const set = (key: keyof AssetFormState, val: string | null) =>
    setForm((f) => ({ ...f, [key]: val }));

  const showForm = isNew || !!editing;

  return (
    <div className="space-y-4">
      <div className="flex justify-between items-center">
        <p className="text-sm text-slate-500">{assets.length}개 에셋</p>
        <Button size="sm" onClick={openNew}>
          <Plus className="h-4 w-4" />
          에셋 추가
        </Button>
      </div>

      {/* Form Panel */}
      {showForm && (
        <Card className="border-indigo-200">
          <CardContent className="py-5 space-y-4">
            <div className="flex items-center justify-between mb-1">
              <p className="font-semibold text-slate-800">{editing ? '에셋 편집' : '새 에셋'}</p>
              <button onClick={closeForm}><X className="h-4 w-4 text-slate-400" /></button>
            </div>

            {/* 이메일 콘텐츠 */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <Input label="에셋 이름 *" value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="내부 관리명" />
              <Input label="이모지" value={form.emoji} onChange={(e) => set('emoji', e.target.value)} placeholder="🎁" />
              <Input label="이메일 제목" value={form.subject} onChange={(e) => set('subject', e.target.value)} placeholder="특별 혜택이 도착했어요" />
              <Input label="프리헤더" value={form.preheader} onChange={(e) => set('preheader', e.target.value)} placeholder="지금 바로 확인하세요" />
              <Input label="이미지 URL" value={form.image_url} onChange={(e) => set('image_url', e.target.value)} placeholder="https://..." className="md:col-span-2" />
              <Textarea label="본문 텍스트" value={form.body_text} onChange={(e) => set('body_text', e.target.value)} rows={3} className="md:col-span-2" placeholder="이메일 본문에 들어갈 텍스트" />
              <Input label="태그 (콤마 구분)" value={form.tags} onChange={(e) => set('tags', e.target.value)} placeholder="프로모션, 신규, VIP" className="md:col-span-2" />
            </div>

            {/* Marketo 연결 설정 */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 p-3 bg-slate-50 rounded-lg">
              <p className="md:col-span-2 text-xs font-semibold text-slate-600 -mb-1">Marketo 연결</p>
              <Input
                label="Marketo 이메일 ID"
                value={form.marketo_email_id ?? ''}
                onChange={(e) => set('marketo_email_id', e.target.value || null)}
                placeholder="15489"
                hint="Phase 1 완료 후 이 ID로 테스트 메일 발송. Marketo Design Studio에서 이메일 URL의 숫자 부분."
                className="md:col-span-2"
              />
            </div>

            {/* My Token 주입 설정 (선택) */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 p-3 bg-indigo-50 rounded-lg">
              <div className="md:col-span-2">
                <p className="text-xs font-semibold text-indigo-700">My Token 주입 설정 (선택)</p>
                <p className="text-xs text-indigo-500 mt-0.5">
                  Marketo 이메일이 <code className="bg-indigo-100 px-0.5 rounded">{'{{my.xxx}}'}</code> My Token을 사용하는 경우에만 설정.
                  발송 승인 시 Smart Campaign 예약 요청에 자동으로 주입됩니다.
                </p>
              </div>
              <Input label="보상 URL 토큰명" value={form.marketo_token_reward_url} onChange={(e) => set('marketo_token_reward_url', e.target.value)} placeholder="{{my.rewardUrl}}" />
              <Input label="제목 토큰명" value={form.marketo_token_subject} onChange={(e) => set('marketo_token_subject', e.target.value)} placeholder="{{my.subjectLine}}" />
              <Input label="이모지 토큰명" value={form.marketo_token_emoji} onChange={(e) => set('marketo_token_emoji', e.target.value)} placeholder="{{my.emoji}}" />
              <Input label="프리헤더 토큰명" value={form.marketo_token_preheader} onChange={(e) => set('marketo_token_preheader', e.target.value)} placeholder="{{my.preheader}}" />
              <Input label="본문 텍스트 토큰명" value={form.marketo_token_body} onChange={(e) => set('marketo_token_body', e.target.value)} placeholder="{{my.bodyText}}" />
              <Input label="이미지 URL 토큰명" value={form.marketo_token_image} onChange={(e) => set('marketo_token_image', e.target.value)} placeholder="{{my.imageUrl}}" />
            </div>

            {error && <p className="text-sm text-red-500">{error}</p>}
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={closeForm}>취소</Button>
              <Button loading={saving} onClick={handleSave}>저장</Button>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Asset Grid */}
      {assets.length === 0 ? (
        <Card>
          <CardContent className="py-16 text-center">
            <ImageIcon className="h-10 w-10 text-slate-300 mx-auto mb-3" />
            <p className="text-slate-500">에셋이 없습니다. 위 버튼으로 추가하세요.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {assets.map((asset) => (
            <Card key={asset.id} className="flex flex-col">
              {asset.image_url && (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={asset.image_url}
                  alt={asset.name}
                  className="w-full h-36 object-cover rounded-t-xl"
                  onError={(e) => { (e.target as HTMLImageElement).style.display = 'none'; }}
                />
              )}
              <CardContent className="flex-1 py-4 space-y-1">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="font-semibold text-slate-800 text-sm">{asset.emoji} {asset.name}</p>
                    {asset.subject && <p className="text-xs text-slate-500 mt-0.5">{asset.subject}</p>}
                  </div>
                  <div className="flex gap-1 shrink-0">
                    <button onClick={() => openEdit(asset)} className="p-1.5 rounded hover:bg-slate-100">
                      <Pencil className="h-3.5 w-3.5 text-slate-400" />
                    </button>
                    <button onClick={() => handleDelete(asset.id)} className="p-1.5 rounded hover:bg-red-50">
                      <Trash2 className="h-3.5 w-3.5 text-red-400" />
                    </button>
                  </div>
                </div>
                {asset.preheader && <p className="text-xs text-slate-400">{asset.preheader}</p>}
                <div className="flex items-center gap-1.5 mt-0.5 flex-wrap">
                  {asset.marketo_email_id && (
                    <span className="text-xs bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded-full font-medium">
                      Email ID: {asset.marketo_email_id}
                    </span>
                  )}
                  {asset.marketo_token_reward_url && (
                    <span className="text-xs bg-indigo-100 text-indigo-600 px-1.5 py-0.5 rounded-full font-medium">
                      Token 주입
                    </span>
                  )}
                </div>
                {asset.tags && (
                  <div className="flex flex-wrap gap-1 mt-1">
                    {asset.tags.split(',').filter(Boolean).map((t) => (
                      <span key={t} className="text-xs bg-slate-100 text-slate-500 rounded-full px-2 py-0.5">
                        {t.trim()}
                      </span>
                    ))}
                  </div>
                )}
                <p className="text-xs text-slate-300 mt-1">{formatDate(asset.updated_at)}</p>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
