# Design: 캠페인 복제 (Clone Campaign)

**Date:** 2026-04-21
**Status:** Approved
**Scope:** `app/campaigns/new/page.tsx`, `components/campaign-wizard.tsx`, `components/campaign-actions.tsx`

---

## Context

매 발송 회차마다 세그먼트·에셋·RTZ 시각은 동일하고 보상 URL과 예약 일시만 바뀌는 경우가 대부분이다. 현재는 캠페인을 처음부터 다시 입력해야 하므로 반복 작업이 발생하고 설정 실수 위험이 있다.

---

## Goal

- 기존 캠페인 설정을 한 번에 복사해 새 생성 폼으로 이동
- 보상 URL·예약 일시는 비워둬 실무자가 반드시 새로 입력하게 함 (CONSTRAINT-02)
- 신규 파일 없음, API 변경 없음

---

## Design

### 진입점

캠페인 상세 페이지 (`/campaigns/[id]`) — `CampaignActions` 컴포넌트에 `<Link>` 버튼 추가.

```
[ 이 설정으로 새 발송 만들기 ]   ← 모든 상태에서 표시 (draft 포함)
```

버튼 클릭 → `/campaigns/new?clone=<id>` 로 이동 (client-side navigate).

### URL 파라미터 처리

`app/campaigns/new/page.tsx` (Server Component):

```
searchParams.clone → DB에서 캠페인 조회
  → defaultValues 구성:
      name:        "<원본 이름> 복사본"
      segmentId:   원본 그대로
      assetId:     원본 그대로
      sendTime:    원본 그대로
      scheduledAt: 빈값 (내일 기본값 유지)
      rewardUrl:   '' (CONSTRAINT-02: 반드시 재입력)
  → CampaignWizard에 defaultValues prop으로 전달
```

clone param 없거나 캠페인 미존재 시 → 기존 동작 그대로 (빈 폼).

### CampaignWizard 변경

`defaultValues` prop 추가:

```typescript
interface DefaultValues {
  name?: string;
  segmentId?: string;
  assetId?: string;
  rewardUrl?: string;
  scheduledAt?: string;
  sendTime?: string;
}
interface CampaignWizardProps {
  segments: SegmentOption[];
  assets: AssetOption[];
  defaultValues?: DefaultValues;
}
```

각 `useState` 초기값을 `defaultValues?.xxx ?? ''` 형태로 변경.
`scheduledAt`은 `defaultValues?.scheduledAt` 없으면 기존 "내일" 기본값 유지.

---

## Files to Modify

| 파일 | 변경 내용 |
|------|---------|
| `components/campaign-actions.tsx` | `Link` 버튼 추가 (`/campaigns/new?clone={id}`) |
| `app/campaigns/new/page.tsx` | `searchParams` 읽기, clone 캠페인 조회, defaultValues 구성 |
| `components/campaign-wizard.tsx` | `defaultValues` prop 추가, useState 초기값 변경 |

---

## Edge Cases

| 케이스 | 처리 |
|--------|------|
| clone ID 미존재 | defaultValues 없이 빈 폼 렌더 |
| 원본 세그먼트/에셋이 삭제됨 | 아래 참고 |
| 보상 URL 채워진 채 복제 | rewardUrl은 항상 '' — CONSTRAINT-02 |

### 삭제된 세그먼트/에셋 상세

현재 Wizard의 `allRequired` 체크는 `!!segmentId`(문자열 비어있는지)만 확인하고 `selectedSeg`(드롭다운에 실제 존재하는지)는 검증하지 않는다. 삭제된 ID가 `defaultValues`로 전달되면 `segmentId`가 채워져 있어 체크를 통과하고, `segment_name: ''`으로 캠페인이 정상 생성된다. 오류는 Phase 1 실행 시에야 발생한다.

**이 기능 구현 시 함께 수정할 것:**  
`CampaignWizard`의 필수 항목 체크를 `!!segmentId && !!selectedSeg`, `!!assetId && !!selectedAsset`으로 강화한다. 삭제된 ID로 복제를 시도하면 체크리스트 항목이 미충족 상태로 표시되어 저장 버튼이 비활성화된다.

---

## Verification

1. 기존 캠페인 상세 페이지 진입 → [이 설정으로 새 발송 만들기] 버튼 표시 확인
2. 버튼 클릭 → 새 캠페인 폼에 세그먼트·에셋·RTZ 시각이 복사됨 확인
3. 이름은 "원본이름 복사본"으로 자동 입력됨 확인
4. 보상 URL은 비어 있음 확인
5. 예약 일시는 내일 기본값임 확인
6. 폼 저장 → 정상적으로 새 캠페인 생성됨 확인
