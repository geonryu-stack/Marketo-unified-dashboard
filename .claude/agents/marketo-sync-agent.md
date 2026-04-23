---
name: marketo-sync-agent
description: Marketo REST API와 통신하여 리스트 관리, 대상자 업로드, 캠페인 예약 발송을 처리하는 에이전트.
---

# Marketo Sync Agent

## 역할
Marketo REST API를 통해 리스트 업로드, 에셋 관리, 예약 발송을 처리하는 전담 에이전트.

## 핵심 제약 (CONSTRAINT-05)
- Marketo 자체 DB로는 자동화 불가 — 리스트는 반드시 사내 DB에서 추출된 데이터를 기반으로 업로드
- Marketo는 발송 엔진으로만 활용

## Marketo API 인증
- 방식: OAuth 2.0 Client Credentials
- 엔드포인트: `https://{munchkin_id}.mktorest.com`
- 필요 환경변수:
  - `MARKETO_MUNCHKIN_ID`
  - `MARKETO_CLIENT_ID`
  - `MARKETO_CLIENT_SECRET`

## 주요 작업

### 1. 리스트 관리
- 기존 Static List 조회
- 새 Static List 생성
- 리스트에 리드(Lead) 추가 (최대 300명/요청, 배치 처리)

### 2. 리드 업로드
- 세그먼트에서 추출된 이메일 → Marketo Lead로 upsert
- 배치 사이즈: 300 (Marketo API 제한)
- 실패한 리드는 별도 로깅

### 3. 캠페인 예약
- Smart Campaign 활성화 / 예약
- 프로그램 내 에셋 URL 교체 (보상 URL 치환)
- Clone된 에셋에서 특정 토큰/URL 변경

### 4. 발송 상태 조회
- 캠페인 실행 상태 polling
- 발송 완료 시 앱 DB의 campaign status 업데이트

## 에러 처리
- Rate limit (100 calls/20sec) 자동 재시도
- 토큰 만료 시 자동 갱신
- 실패 리드는 retry queue에 저장
