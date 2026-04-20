# Design: Marketo Dropdown Suffix Filter

**Date:** 2026-04-20  
**Status:** Approved  
**Scope:** `app/api/marketo/campaigns/route.ts`, `app/api/marketo/lists/route.ts`

---

## Context

세그먼트 폼의 두 MarketoSelect 드롭다운(`/api/marketo/campaigns`, `/api/marketo/lists`)이 현재 Marketo 전체 항목(100+개)을 불러온다. 실무 자동발송에 실제로 쓰이는 항목은 4개씩뿐이며, 불필요한 항목이 노출되어 선택 실수와 비효율이 발생한다.

**현재 확정된 4개 그룹:**

| 그룹 | Program ID | Campaign ID | List ID |
|------|-----------|-------------|---------|
| Active A | 7309 | 7610 (`ActiveA_Autosend`) | 8293 (`ActiveA_Autosend_Audience`) |
| Active B | 7310 | 7611 (`ActiveB_Autosend`) | 8294 (`ActiveB_Autosend_Audience`) |
| FP Active | 7312 | 7613 (`FPActive_Autosend`) | 8296 (`FPActive_Autosend_Audience`) |
| NP Active | 7311 | 7612 (`NPActive_Autosend`) | 8295 (`NPActive_Autosend_Audience`) |

모든 캠페인 파일명은 `_Autosend`, 모든 리스트 파일명은 `_Autosend_Audience`로 끝난다.

---

## Goal

- 드롭다운에 관련 항목만 표시 (현재 4개, 이후 네이밍 컨벤션 준수 시 자동 포함)
- 새 그룹 추가 시 앱 코드·환경변수 변경 없이 자동 반영
- 변경 범위 최소화 (UI, DB, 타입 변경 없음)

---

## Design

### Approach: Server-side Name Suffix Filter

API 라우트에서 Marketo 전체 응답을 받은 뒤 파일명 suffix로 필터링하여 반환한다.

- 캠페인: `name.endsWith(MARKETO_CAMPAIGN_SUFFIX)` — 기본값 `_Autosend`
- 리스트: `name.endsWith(MARKETO_LIST_SUFFIX)` — 기본값 `_Autosend_Audience`

suffix는 환경변수로 오버라이드 가능하여, 향후 네이밍 규칙이 바뀌어도 코드 변경 없이 대응한다.

### Data Flow (After)

```
Marketo REST API → 전체 목록
  ↓
API route: .filter(name.endsWith(suffix))
  ↓
MarketoSelect 컴포넌트: 관련 항목 4개만 수신
  ↓
드롭다운: Active A / Active B / FP Active / NP Active
```

---

## Files to Modify

### 1. `app/api/marketo/campaigns/route.ts`

```typescript
const CAMPAIGN_SUFFIX = process.env.MARKETO_CAMPAIGN_SUFFIX ?? '_Autosend';

export async function GET(req: NextRequest) {
  try {
    const { searchParams } = req.nextUrl;
    const programId = searchParams.get('programId');
    const campaigns = await getSmartCampaigns(programId ? parseInt(programId, 10) : undefined);
    const filtered = campaigns.filter((c) => c.name.endsWith(CAMPAIGN_SUFFIX));
    return Response.json({ success: true, data: filtered });
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    return Response.json({ success: false, error: msg }, { status: 500 });
  }
}
```

### 2. `app/api/marketo/lists/route.ts`

```typescript
const LIST_SUFFIX = process.env.MARKETO_LIST_SUFFIX ?? '_Autosend_Audience';

export async function GET(req: NextRequest) {
  try {
    const { searchParams } = req.nextUrl;
    const programId = searchParams.get('programId') ?? undefined;
    const lists = await getStaticLists(programId);
    const filtered = lists.filter((l) => l.name.endsWith(LIST_SUFFIX));
    return Response.json({ success: true, data: filtered });
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    return Response.json({ success: false, error: msg }, { status: 500 });
  }
}
```

---

## Environment Variables (Optional)

기본값이 있으므로 설정하지 않아도 동작한다. 네이밍 규칙 변경 시에만 추가.

```bash
# .env.local (선택)
MARKETO_CAMPAIGN_SUFFIX=_Autosend
MARKETO_LIST_SUFFIX=_Autosend_Audience
```

---

## Edge Cases

| 케이스 | 처리 |
|--------|------|
| suffix 불일치로 0개 반환 | MarketoSelect가 "항목 없음" 표시 (기존 동작) |
| Marketo API 오류 | 기존 에러 핸들링 그대로 (변경 없음) |
| 새 그룹이 컨벤션 불일치 | 드롭다운에 미표시 — Marketo 파일명 수정으로 해결 |

---

## Convention Rule (운영 규칙)

새 발송 그룹 추가 시 Marketo에서 아래 네이밍 규칙 준수:

- Smart Campaign: `{GroupName}_Autosend`
- Static List: `{GroupName}_Autosend_Audience`

이 규칙을 지키면 앱 코드 변경 없이 드롭다운에 자동 반영됨.

---

## Verification

1. 앱 실행 후 세그먼트 생성 폼 진입
2. "Marketo Campaign" 드롭다운 열기 → `_Autosend` suffix 항목 4개만 표시 확인
3. "Audience Static List" 드롭다운 열기 → `_Autosend_Audience` suffix 항목 4개만 표시 확인
4. 기존 세그먼트 편집 → 저장된 ID가 이름으로 정상 표시 확인
