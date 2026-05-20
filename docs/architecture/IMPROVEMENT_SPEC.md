# IMPROVEMENT_SPEC.md — 운영자 검증 후 개선 요구 4건

> 작성: 2026-05-20. 운영자(geonryu) 실측 후 전달한 4건의 개선 요구를 분석하고 즉시 패치 vs 사용자 결정 vs 장기 작업으로 분류.

## 요약 매트릭스

| # | 요구 | 영향 zone | 난이도 | 본 sprint 처리 |
|---|------|-----------|--------|-----------------|
| 1 | 세그먼트 필터 후행 가드의 일률성 | DB + ORCH | ★★ 중 | **사용자 결정 필요** — 옵션 3안 제시 |
| 2 | Marketo 연결 ID 입력 단순화 | DB + UI | ★ 낮음 | ✅ **즉시 패치** — 발송 그룹 프리셋 |
| 3 | 콘텐츠 프리셋 영문화 + 플로우 효율화 | ASSET | ★ 낮음 | ✅ **즉시 패치** — 영문화 + 직전 회차 토큰 복사 |
| 4 | Frequency cap + 중복 대상자 자동 제외 | DB + ORCH + MKT | ★★★ 높음 | **사용자 결정 필요** — 데이터 모델/PII 정책 결정 후 별도 sprint |

---

## #1. 필터 조건 후행 가드의 일률성

### 현재 (Sprint 0~3 누적)

`api/internal-db.php` preview + ScheduleRunner.extract_campaign_leads는 다음을 *모든 세그먼트에 동일하게* 적용:
- `consent_guard` 토글 ON(기본) 시 `marketing_consent=1 AND is_active=1` 자동 부착
- 운영자가 직접 추가한 필터 외에는 그 외 가드 없음

### 문제

운영자 의도 추정 — "세그먼트 *유형*에 따라 다른 후행 가드가 필요"한데 일률 적용 중. 예시:

| 세그먼트 유형 | 필요 가드 | 현재 |
|---------------|----------|------|
| Active (활성 유저) | 동의 + 활성 + 30일 내 다른 발송 없음 | 동의/활성만 |
| Re-engagement (휴면 복귀) | 동의 + 비활성 + bounce 임계 미만 | 동의만 (활성=ON이면 0건) |
| Transactional (거래성 알림) | 가드 최소화 (법적 OK) | 동의=ON 강제 |
| Lifecycle (특정 이벤트 후) | 활성 + 이벤트 90일 내 | 동의/활성만 |

### 옵션 (사용자 결정 필요)

**(A) 세그먼트 유형 컬럼 도입** ★ 추천
- `segments.type ENUM('active','reengagement','transactional','lifecycle','custom')`
- 유형별 default 가드를 `get_field_defs`처럼 PHP 상수로 정의
- 운영자가 유형 선택만 하면 가드는 자동 → 운영 표준화
- 마이그레이션 1건, 새 컬럼 1개, 가드 함수 1개

**(B) 가드 토글을 더 풍부하게** (Sprint 0 동의 가드 확장)
- "동의자만", "활성 유저만", "최근 N일 내 다른 발송 받지 않은 사람만", "bounce 임계 미만" 4개 독립 토글
- 운영자가 매번 골라야 함 — 표준화 약함, 유연성 높음

**(C) 두 방식 결합** (가장 안전)
- 유형 선택 + 유형별 default 토글 자동 ON, 운영자가 override 가능
- 구현 부담 = A + B

### 권장
**(A)** — 빠른 구현, 운영 표준화, 후속 KPI(유형별 coverage 비교 등) 활용 가능. 단, 유형 정의를 사용자와 합의 필요.

---

## #2. Marketo 연결 ID 입력 단순화 ✅ 즉시 패치

### 현재
세그먼트 생성 화면에서 `Program ID`, `Audience List ID`, `Email Program ID` 3개 텍스트 input. 운영자가 Marketo UI 가서 찾아 복사·붙여넣기.

### 패치 (이번에 적용)

**`groups` 테이블에 program_id / email_program_id 추가 + 운영 기본 4그룹 시드**
- 마이그레이션: `sql/migrations/groups_marketo_ids.sql`
  - `marketo_program_id INT NULL` (Sprint 0 메모리의 발송 그룹 IDs 자동 시드)
  - `marketo_email_program_id INT NULL` (운영자가 첫 사용 시 입력)
- 신규 endpoint: `GET /api/groups` → 4개 그룹 + ID 노출
- 세그먼트 페이지(new/edit) 상단에 **"발송 그룹"** 셀렉트 추가
  - 선택 시 3개 input 자동 채움 + `<input readonly>` 으로 변환 (옆에 "직접 입력" 토글)
- 자주 쓰는 그룹은 1클릭, 신규/예외는 직접 입력 가능

### 패치 후
- 운영자: "Active A" 클릭 한 번 → Program 7309 / List 8293 / EP(미입력 시 운영자가 1회 채움) 자동
- 입력 오류·잘못된 ID 사용 위험 ↓

---

## #3. 콘텐츠 프리셋 영문화 + 플로우 효율화 ✅ 즉시 패치

### 현재
- `CONTENT_PRESETS` JS 상수에 한글 라벨/제목/프리헤더 7개
- `content_presets` DB 테이블은 Sprint 2/3에 추가됐지만 시드 없음 → 첫 로드 시 JS fallback 노출
- 새 캠페인 생성 시 운영자가 매 회차 토큰 4종(이모지/제목/프리헤더/URL)을 처음부터 입력

### 패치 (이번에 적용)

**(a) CONTENT_PRESETS 영문화**
- 운영 대상 사용자(global 마케팅) 영문 UI 컨텍스트 정합
- 라벨/제목/프리헤더 7건 영문 재작성 ("Reward Notice", "Welcome Back", "Limited-Time Offer" 등)

**(b) 세그먼트 선택 시 직전 회차 토큰 자동 채움**
- 새 캠페인 페이지에서 세그먼트 셀렉트 onchange:
  - `/api/campaigns/latest-tokens?segment_id={id}` 호출 → 직전 sent 캠페인의 4개 토큰 반환
  - input이 비어있는 경우에만 자동 채움 (운영자가 입력한 값 우선)
  - 상단에 작은 안내 "이전 회차 'XYZ'의 토큰을 가져왔습니다. 확인 후 수정하세요."
- 기존 직전 회차 비교 endpoint를 재사용 또는 단순화 endpoint 신설

### 패치 후
- 영문 운영자: 프리셋 선택 → 한글이 아닌 자신의 언어로 표시
- 매 주 같은 segment 발송 시: 세그먼트 선택만으로 직전 회차 토큰 자동 → 보상 URL 1개만 수정하면 끝
- 결재 시간 단축 (STRATEGY §9 KPI에 추가 기여)

---

## #4. Frequency Cap + 중복 대상자 자동 제외 (사용자 결정 필요)

### 문제 정의
- 한 lead(이메일)가 일/주/월 단위로 N건 이상 이메일 수신 시 발송 피로(send fatigue) → 구독 해지/스팸 신고 증가
- 같은 lead가 여러 segment에 중복 포함 시 같은 주에 여러 캠페인 동시 발송 위험
- 사내 DB의 이메일 주소 단위로 monitor + 후속 발송에서 자동 제외 필요

### 현재 시스템 한계
- **lead 추적 데이터가 없음** — Marketo Static List는 캠페인별로 매번 비우고 새로 채움. 캠페인 사이 lead overlap 추적 불가
- 캘린더 페이지는 *캠페인 단위* 일정만 보여줌, lead 단위 빈도 분석 X

### 핵심 의사결정 3건

**결정 1: lead 추적 데이터 모델**

| 옵션 | 저장 컬럼 | PII 노출 | 가시성 | 권장 시나리오 |
|------|----------|---------|--------|---------------|
| (A) email_hash | `campaign_id, email_hash(SHA256), send_time` | ✓ 안전 | 운영자는 hash만 봄, 평문 매핑 안 됨 | PII 보호 최우선 |
| (B) marketo_lead_id | `campaign_id, marketo_lead_id, send_time` | ✓ 안전 | Marketo lead로 추적, 평문 비공개 | Marketo와 일관성 |
| (C) plaintext + mask | `campaign_id, email, send_time` | ✗ 평문 저장 | 운영자가 마스킹 UI로만 봄 | 디버깅 친화, 위험 큼 |

**권장: (B) marketo_lead_id** — `MarketoAPI::upsertLeads()`가 이미 lead ID 반환. PII 추가 노출 없음. 단, lead가 Marketo에서 삭제되면 추적 끊김.

**결정 2: frequency cap 정책**

| 단위 | 보수적 | 표준 | 공격적 |
|------|--------|------|--------|
| 일 | 1건 | 2건 | 3건 |
| 주 | 2건 | 3건 | 5건 |
| 월 | 6건 | 10건 | 15건 |

운영자가 segments.type 별로 다르게 설정 가능하도록(#1과 연결).
**Transactional은 frequency cap에서 제외 권장** (법적/필수성).

**결정 3: 자동 제외 트리거 시점**

| 옵션 | 시점 | 장단점 |
|------|------|--------|
| (X) 추출 시점 | `ScheduleRunner.extract_campaign_leads` 직후 | 가장 빠른 차단, lead_count 즉시 정확 |
| (Y) 발송 직전 | finalize 직전 | 다른 캠페인 상태 변화 반영, 복잡도 ↑ |

**권장: (X)** — 단순, 운영자 인지 빠름. 결재 카드에 "주 N건 정책으로 X명 자동 제외됨" 표시.

### 구현 단계 (별도 sprint 권장)

**Sprint 4 (제안)**:
1. `campaign_leads` 테이블 신설 + 마이그레이션
2. ScheduleRunner.extract에 lead_id 적재
3. extract 단계에서 frequency cap 체크 + 자동 제외 + 운영자 경고 메시지
4. 캘린더에 "주 평균 발송수 / lead" 표시 (segment 단위 집계)
5. 결재 카드에 "이 캠페인: X명 자동 제외 (주 N건 초과 예정)"

**예상 LOC**: ~600줄. 5트랙 분배 가능 (DB가 핵심 — 모델 + 자동 제외 로직).

---

## 본 메시지에서 즉시 적용하는 패치 요약

✅ #2 — 발송 그룹 프리셋 (`groups` 확장 + UI 셀렉트)
✅ #3 — CONTENT_PRESETS 영문화 + 직전 회차 토큰 자동 채움 endpoint

🟡 #1, #4 — 본 문서 옵션을 보고 사용자가 결정한 안에 따라 별도 Sprint 4로 진행
