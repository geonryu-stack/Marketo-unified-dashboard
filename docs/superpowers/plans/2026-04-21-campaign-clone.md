# Campaign Clone Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 기존 캠페인의 세그먼트·에셋·RTZ 시각을 복사한 채 새 캠페인 생성 폼으로 이동하는 "이 설정으로 새 발송 만들기" 기능 구현

**Architecture:** 캠페인 상세 페이지에 Link 버튼 추가 → `/campaigns/new?clone=<id>` 로 이동 → Server Component에서 원본 캠페인 조회 → `defaultValues`로 Wizard에 전달. 신규 파일·API 없음.

**Tech Stack:** Next.js 16.2.4 (App Router, searchParams는 Promise), React, better-sqlite3, Tailwind CSS

---

## File Map

| 파일 | 변경 내용 |
|------|---------|
| `components/campaign-wizard.tsx` | `DefaultValues` 타입 export, `defaultValues` prop 추가, useState 초기값 변경, allRequired 검증 강화 |
| `app/campaigns/new/page.tsx` | `searchParams: Promise<{ clone?: string }>` 읽기, clone 조회, defaultValues 전달 |
| `components/campaign-actions.tsx` | [이 설정으로 새 발송 만들기] Link 버튼 추가 |

---

## Task 1: CampaignWizard — defaultValues prop + allRequired 검증 강화

**Files:**
- Modify: `components/campaign-wizard.tsx`

- [ ] **Step 1: `DefaultValues` 타입 추가 및 export**

  `components/campaign-wizard.tsx` 의 `CampaignWizardProps` 위에 삽입:

  ```typescript
  export interface DefaultValues {
    name?: string;
    segmentId?: string;
    assetId?: string;
    sendTime?: string;
    rewardUrl?: string;
  }
  ```

  `CampaignWizardProps` 인터페이스 변경:

  ```typescript
  interface CampaignWizardProps {
    segments: SegmentOption[];
    assets: AssetOption[];
    defaultValues?: DefaultValues;
  }
  ```

  함수 시그니처 변경:

  ```typescript
  export function CampaignWizard({ segments, assets, defaultValues }: CampaignWizardProps) {
  ```

- [ ] **Step 2: useState 초기값을 defaultValues 기반으로 변경**

  기존:
  ```typescript
  const [name, setName] = useState('');
  const [segmentId, setSegmentId] = useState('');
  const [assetId, setAssetId] = useState('');
  const [rewardUrl, setRewardUrl] = useState('');
  const [sendTime, setSendTime] = useState('10:00');
  ```

  변경 후:
  ```typescript
  const [name, setName] = useState(defaultValues?.name ?? '');
  const [segmentId, setSegmentId] = useState(defaultValues?.segmentId ?? '');
  const [assetId, setAssetId] = useState(defaultValues?.assetId ?? '');
  const [rewardUrl, setRewardUrl] = useState(defaultValues?.rewardUrl ?? '');
  const [sendTime, setSendTime] = useState(defaultValues?.sendTime ?? '10:00');
  ```

  `scheduledAt`은 변경 없음 — 내일 기본값 유지.

- [ ] **Step 3: allRequired 검증 강화**

  기존 checks 배열:
  ```typescript
  const checks = [
    { label: '캠페인 이름', ok: !!name.trim() },
    { label: '발송 세그먼트 선택', ok: !!segmentId },
    { label: '에셋 선택', ok: !!assetId },
    { label: '보상 URL 입력', ok: !!rewardUrl.trim() },
    { label: '예약 일시 설정', ok: !!scheduledAt },
    { label: 'RTZ 발송 시각 설정', ok: !!sendTime.trim() },
  ];
  ```

  변경 후 (`selectedSeg`, `selectedAsset` 존재 여부까지 검증):
  ```typescript
  const checks = [
    { label: '캠페인 이름', ok: !!name.trim() },
    { label: '발송 세그먼트 선택', ok: !!segmentId && !!selectedSeg },
    { label: '에셋 선택', ok: !!assetId && !!selectedAsset },
    { label: '보상 URL 입력', ok: !!rewardUrl.trim() },
    { label: '예약 일시 설정', ok: !!scheduledAt },
    { label: 'RTZ 발송 시각 설정', ok: !!sendTime.trim() },
  ];
  ```

- [ ] **Step 4: lint 통과 확인**

  ```bash
  cd /Users/geonwoo/marketo-send-automation && npm run lint 2>&1
  ```

  예상: 0 errors (기존 4 warnings는 그대로)

---

## Task 2: `/campaigns/new` 페이지 — clone 파라미터 처리

**Files:**
- Modify: `app/campaigns/new/page.tsx`

- [ ] **Step 1: import에 `Campaign`과 `DefaultValues` 추가**

  기존:
  ```typescript
  import { getDb } from '@/db/sqlite';
  import { Segment, AssetLibraryItem } from '@/lib/types';
  import { CampaignWizard } from '@/components/campaign-wizard';
  ```

  변경 후:
  ```typescript
  import { getDb } from '@/db/sqlite';
  import { Segment, AssetLibraryItem, Campaign } from '@/lib/types';
  import { CampaignWizard, DefaultValues } from '@/components/campaign-wizard';
  ```

- [ ] **Step 2: 함수 시그니처와 본문 전체 교체**

  기존:
  ```typescript
  export default function NewCampaignPage() {
    const db = getDb();
    const segments = db.prepare('SELECT id, name, last_count FROM segments ORDER BY name').all() as Pick<Segment, 'id' | 'name' | 'last_count'>[];
    const assets = db.prepare('SELECT id, name, emoji, subject, marketo_email_id FROM asset_library ORDER BY name').all() as Pick<AssetLibraryItem, 'id' | 'name' | 'emoji' | 'subject' | 'marketo_email_id'>[];

    return (
      <div className="p-8 max-w-2xl">
        <div className="mb-6">
          <h1 className="text-2xl font-bold text-slate-900">새 캠페인</h1>
          <p className="text-sm text-slate-500 mt-1">세그먼트, 에셋, 발송 일정을 설정합니다.</p>
        </div>
        <CampaignWizard segments={segments} assets={assets} />
      </div>
    );
  }
  ```

  변경 후:
  ```typescript
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
  ```

- [ ] **Step 3: lint 통과 확인**

  ```bash
  cd /Users/geonwoo/marketo-send-automation && npm run lint 2>&1
  ```

  예상: 0 errors

---

## Task 3: CampaignActions — [이 설정으로 새 발송 만들기] 버튼 추가

**Files:**
- Modify: `components/campaign-actions.tsx`

- [ ] **Step 1: import에 `Link`와 `Copy` 아이콘 추가**

  기존:
  ```typescript
  import { Play, Trash2, RefreshCw, CheckCheck, RotateCcw, XCircle, CalendarCheck, ShieldCheck } from 'lucide-react';
  ```

  변경 후:
  ```typescript
  import Link from 'next/link';
  import { Play, Trash2, RefreshCw, CheckCheck, RotateCcw, XCircle, CalendarCheck, ShieldCheck, Copy } from 'lucide-react';
  ```

- [ ] **Step 2: 버튼 그룹 아래에 Link 버튼 추가**

  `{error && <p ...>}` 바로 위, `</div>` (최외곽 flex div) 안에 삽입:

  기존 (최외곽 div 마지막 부분):
  ```typescript
      {error && <p className="text-xs text-red-500">{error}</p>}
    </div>
  ```

  변경 후:
  ```typescript
      <Link
        href={`/campaigns/new?clone=${campaign.id}`}
        className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50 transition-colors font-medium"
      >
        <Copy className="h-3.5 w-3.5" />
        이 설정으로 새 발송 만들기
      </Link>
      {error && <p className="text-xs text-red-500">{error}</p>}
    </div>
  ```

- [ ] **Step 3: lint 통과 확인**

  ```bash
  cd /Users/geonwoo/marketo-send-automation && npm run lint 2>&1
  ```

  예상: 0 errors

---

## Task 4: 전체 커밋

- [ ] **Step 1: 변경 파일 스테이징 및 커밋**

  ```bash
  cd /Users/geonwoo/marketo-send-automation
  git add components/campaign-wizard.tsx app/campaigns/new/page.tsx components/campaign-actions.tsx
  git commit -m "feat: 캠페인 복제 — 이 설정으로 새 발송 만들기

  - CampaignWizard에 defaultValues prop 추가 (name/segmentId/assetId/sendTime/rewardUrl)
  - allRequired 검증 강화: selectedSeg/selectedAsset 존재 여부까지 확인
  - /campaigns/new?clone=<id>로 원본 설정 복사 (rewardUrl은 CONSTRAINT-02로 항상 비움)
  - CampaignActions에 복제 Link 버튼 추가 (모든 상태에서 표시)

  Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
  ```

---

## 검증 체크리스트

기능 구현 완료 후 수동으로 확인:

- [ ] 기존 캠페인 상세 페이지에 [이 설정으로 새 발송 만들기] 버튼 표시됨
- [ ] 버튼 클릭 → 새 캠페인 폼에 세그먼트·에셋·RTZ 시각이 복사됨
- [ ] 이름은 "원본이름 복사본"으로 자동 입력됨
- [ ] 보상 URL은 비어 있음
- [ ] 예약 일시는 내일 기본값
- [ ] 삭제된 세그먼트로 복제 시: 세그먼트 체크리스트 항목 미충족 표시, 생성 버튼 비활성
- [ ] 정상 폼 저장 → 새 캠페인 draft 생성됨
