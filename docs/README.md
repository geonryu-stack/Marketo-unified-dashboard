# docs/

프로젝트 문서. 루트(`/`)에는 운영/언어/툴 컨벤션상 *반드시 거기 있어야 하는* 파일들만 두고,
나머지는 모두 `docs/` 또는 그 하위에 둔다.

## 구성

- `DEV_ENVIRONMENT.md` — 회사 표준 스택, XAMPP 로컬 실행 가이드. CLAUDE.md가 `@docs/DEV_ENVIRONMENT.md`로 include.
- `architecture/` — 아키텍처 5종 + 전략 1종
  - `OVERVIEW.md` — 4계층 도식, 7개 핵심 불변식
  - `PIPELINE.md` — 캠페인 1건의 13스테이지 정규 파이프라인
  - `HARNESS.md` — 가드/재시도/관측/격리/킬스위치 × 13스테이지 매트릭스
  - `CRITICS.md` — 단계 간 비평/검증 게이트
  - `CONTEXT_MAP.md` — 5개 직무 롤, 소유권/안전영역, 머지 순서
  - `STRATEGY.md` — 25개 개선안 · 4-스프린트 병렬 실행 전략 (5개 도메인 합의)

## 코딩/운영시 진입 순서

1. `/CLAUDE.md` → `docs/DEV_ENVIRONMENT.md` (스택 확인)
2. `docs/architecture/OVERVIEW.md` (7개 불변식)
3. 작업 zone에 해당하는 `PIPELINE.md` 스테이지 + `HARNESS.md` 매트릭스
4. `CONTEXT_MAP.md` §4 "변경 영향 점검표"
