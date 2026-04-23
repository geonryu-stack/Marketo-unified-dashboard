/**
 * Marketo 연결 테스트 & 핵심 기능 검증 스크립트 (DB 불필요)
 *
 * 사용법:
 *   node scripts/test-marketo.mjs              — 기본 연결 + 자산 조회
 *   node scripts/test-marketo.mjs token        — My Token 설정 테스트
 *   node scripts/test-marketo.mjs sample       — 테스트 메일 발송
 *   node scripts/test-marketo.mjs full         — token + sample 전체
 *
 * .env.local을 자동으로 로드합니다 (프로젝트 루트 기준).
 * 추가로 필요한 환경변수:
 *   EMAIL_PROGRAM_ID  — Email Program ID (token/sample 모드 필수)
 *   STATIC_LIST_ID    — Static List ID (lead 모드 시 필요)
 *   TEST_EMAIL        — 테스트 메일 수신 주소 (sample 모드 필수)
 */

import { readFileSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

// .env.local 자동 로드 (key=value 파싱, 주석·빈 줄 무시)
const __dir = dirname(fileURLToPath(import.meta.url));
const envPath = resolve(__dir, '..', '.env.local');
try {
  const lines = readFileSync(envPath, 'utf8').split('\n');
  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const eqIdx = trimmed.indexOf('=');
    if (eqIdx < 1) continue;
    const key = trimmed.slice(0, eqIdx).trim();
    const val = trimmed.slice(eqIdx + 1).trim();
    if (!(key in process.env)) process.env[key] = val;
  }
} catch {
  // .env.local 없으면 기존 환경변수 그대로 사용
}

const MUNCHKIN_ID   = process.env.MARKETO_MUNCHKIN_ID   ?? '';
const CLIENT_ID     = process.env.MARKETO_CLIENT_ID     ?? '';
const CLIENT_SECRET = process.env.MARKETO_CLIENT_SECRET ?? '';

if (!MUNCHKIN_ID || !CLIENT_ID || !CLIENT_SECRET) {
  console.error('❌ 환경변수 누락: MARKETO_MUNCHKIN_ID, MARKETO_CLIENT_ID, MARKETO_CLIENT_SECRET');
  console.error('   .env.local 파일을 확인하세요.');
  process.exit(1);
}

const BASE_URL = `https://${MUNCHKIN_ID}.mktorest.com`;

// ── 환경변수 오버라이드 (필요 시 직접 입력) ──────────────────────────
const EMAIL_PROGRAM_ID = process.env.EMAIL_PROGRAM_ID ?? '';   // e.g. '12345'
const STATIC_LIST_ID   = process.env.STATIC_LIST_ID   ?? '';   // e.g. '67890'
const TEST_EMAIL       = process.env.TEST_EMAIL        ?? '';   // e.g. 'you@company.com'
// ─────────────────────────────────────────────────────────────────────

const mode = process.argv[2] ?? 'info';

// ── 토큰 발급 ─────────────────────────────────────────────────────────
async function getToken() {
  const url = `${BASE_URL}/identity/oauth/token?grant_type=client_credentials&client_id=${CLIENT_ID}&client_secret=${CLIENT_SECRET}`;
  const res  = await fetch(url);
  const data = await res.json();
  if (!data.access_token) throw new Error(`Token 발급 실패: ${JSON.stringify(data)}`);
  return data.access_token;
}

async function mkGet(token, path) {
  const res = await fetch(`${BASE_URL}${path}`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  return res.json();
}

async function mkPost(token, path, body, formEncoded = false) {
  const headers = { Authorization: `Bearer ${token}` };
  let reqBody;
  if (formEncoded) {
    headers['Content-Type'] = 'application/x-www-form-urlencoded';
    reqBody = new URLSearchParams(body).toString();
  } else {
    headers['Content-Type'] = 'application/json';
    reqBody = JSON.stringify(body);
  }
  const res = await fetch(`${BASE_URL}${path}`, { method: 'POST', headers, body: reqBody });
  return res.json();
}

// ── 1. 기본 연결 + 자산 조회 ─────────────────────────────────────────
async function runInfo(token) {
  console.log('✅ Marketo 연결 성공\n');

  // Email 15489 확인
  console.log('── Email 15489 (Time\'s ticking) ──');
  const emailData = await mkGet(token, '/rest/asset/v1/email/15489.json');
  if (emailData.result?.length) {
    const e = emailData.result[0];
    console.log(`  ID: ${e.id}  |  이름: ${e.name}  |  상태: ${e.status}`);
    console.log(`  폴더: ${JSON.stringify(e.folder)}`);
  } else {
    console.log('  조회 실패:', JSON.stringify(emailData));
  }

  // Email Program 조회 (ID가 있을 때)
  if (EMAIL_PROGRAM_ID) {
    console.log(`\n── Email Program ${EMAIL_PROGRAM_ID} ──`);
    const prog = await mkGet(token, `/rest/asset/v1/emailProgram/${EMAIL_PROGRAM_ID}.json`);
    if (prog.result?.length) {
      const p = prog.result[0];
      console.log(`  ID: ${p.id}  |  이름: ${p.name}  |  타입: ${p.type}`);
      console.log(`  이메일 ID: ${p.emailId ?? '미설정'}  |  상태: ${p.status ?? '-'}`);
    } else {
      console.log('  조회 실패:', JSON.stringify(prog));
    }
  } else {
    console.log('\n⚠️  EMAIL_PROGRAM_ID 미설정 — Email Program 조회 건너뜀');
    console.log('   Marketo에서 Email Program 생성 후 이 스크립트 상단에 ID를 입력하세요.');
  }

  // Static List 조회 (ID가 있을 때)
  if (STATIC_LIST_ID) {
    console.log(`\n── Static List ${STATIC_LIST_ID} ──`);
    const list = await mkGet(token, `/rest/v1/lists/${STATIC_LIST_ID}.json`);
    if (list.result?.length) {
      const l = list.result[0];
      console.log(`  ID: ${l.id}  |  이름: ${l.name}  |  프로그램: ${l.programName ?? '-'}`);
    } else {
      console.log('  조회 실패:', JSON.stringify(list));
    }
  } else {
    console.log('\n⚠️  STATIC_LIST_ID 미설정 — Static List 조회 건너뜀');
  }
}

// ── 2. My Token 설정 테스트 ───────────────────────────────────────────
async function runTokenTest(token) {
  if (!EMAIL_PROGRAM_ID) {
    console.error('❌ EMAIL_PROGRAM_ID를 설정해주세요.');
    process.exit(1);
  }

  console.log(`\n── My Token 설정 테스트 (Program ${EMAIL_PROGRAM_ID}) ──`);
  const tokens = [
    { name: '{{my.rewardUrl}}',    value: 'https://example.com/reward/test' },
    { name: '{{my.subjectLine}}',  value: '[테스트] My Token 주입 확인' },
  ];

  const result = await mkPost(token, `/rest/asset/v1/program/${EMAIL_PROGRAM_ID}/tokens.json`, { tokens });
  if (result.success) {
    console.log(`  ✅ My Token ${tokens.length}개 설정 완료`);
    tokens.forEach(t => console.log(`     ${t.name} = ${t.value}`));
  } else {
    console.log('  ❌ Token 설정 실패:', JSON.stringify(result));
  }
}

// ── 3. 테스트 메일 발송 ───────────────────────────────────────────────
async function runSampleEmail(token) {
  if (!EMAIL_PROGRAM_ID) {
    console.error('❌ EMAIL_PROGRAM_ID를 설정해주세요.');
    process.exit(1);
  }
  if (!TEST_EMAIL) {
    console.error('❌ TEST_EMAIL을 설정해주세요. (예: TEST_EMAIL=you@company.com node scripts/test-marketo.mjs sample)');
    process.exit(1);
  }

  // Email Program에서 emailId 가져오기
  console.log(`\n── Email Program ${EMAIL_PROGRAM_ID}에서 emailId 조회 ──`);
  const prog = await mkGet(token, `/rest/asset/v1/emailProgram/${EMAIL_PROGRAM_ID}.json`);
  const emailId = prog.result?.[0]?.emailId;
  if (!emailId) {
    console.error('❌ Email Program에 연결된 이메일이 없습니다. Marketo UI에서 Email 탭을 확인하세요.');
    process.exit(1);
  }
  console.log(`  이메일 ID: ${emailId}`);

  console.log(`\n── 테스트 메일 발송 → ${TEST_EMAIL} ──`);
  const result = await mkPost(
    token,
    `/rest/asset/v1/email/${emailId}/sendSample.json`,
    { emailAddress: TEST_EMAIL },
    true  // form-encoded
  );
  if (result.success) {
    console.log(`  ✅ 테스트 메일 발송 완료 → ${TEST_EMAIL}`);
  } else {
    console.log('  ❌ 발송 실패:', JSON.stringify(result));
  }
}

// ── 리드 업서트 + Static List 추가 테스트 ────────────────────────────
async function runLeadTest(token) {
  if (!STATIC_LIST_ID) {
    console.log('\n⚠️  STATIC_LIST_ID 미설정 — 리드 업로드 테스트 건너뜀');
    return;
  }

  const testEmails = ['test-lead-1@example.com', 'test-lead-2@example.com'];
  console.log(`\n── 테스트 리드 업서트 (${testEmails.length}명) ──`);

  const upsertRes = await mkPost(token, '/rest/v1/leads.json', {
    action: 'createOrUpdate',
    input: testEmails.map(email => ({ email })),
    lookupField: 'email',
  });

  if (upsertRes.success) {
    const ids = upsertRes.result.map(r => r.id);
    console.log(`  ✅ 업서트 완료. 리드 ID: ${ids.join(', ')}`);

    // Static List에 추가
    console.log(`  Static List(${STATIC_LIST_ID})에 추가 중...`);
    const addRes = await mkPost(token, `/rest/v1/lists/${STATIC_LIST_ID}/leads.json`, {
      input: ids.map(id => ({ id })),
    });
    if (addRes.success) {
      console.log(`  ✅ Static List 추가 완료`);
    } else {
      console.log('  ❌ Static List 추가 실패:', JSON.stringify(addRes));
    }
  } else {
    console.log('  ❌ 업서트 실패:', JSON.stringify(upsertRes));
  }
}

// ── 메인 ─────────────────────────────────────────────────────────────
async function main() {
  console.log('=== Marketo 연동 테스트 ===\n');
  const token = await getToken();

  if (mode === 'info' || mode === 'full') {
    await runInfo(token);
  }
  if (mode === 'token' || mode === 'full') {
    await runTokenTest(token);
  }
  if (mode === 'sample' || mode === 'full') {
    await runSampleEmail(token);
  }
  if (mode === 'lead') {
    await runLeadTest(token);
  }

  console.log('\n=== 완료 ===');
}

main().catch(err => {
  console.error('\n❌ 오류:', err.message);
  process.exit(1);
});
