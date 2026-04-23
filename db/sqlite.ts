import Database from 'better-sqlite3';
import path from 'path';
import fs from 'fs';

const DB_PATH = path.join(process.cwd(), 'data', 'app.db');

let _db: Database.Database | null = null;

export function getDb(): Database.Database {
  if (!_db) {
    const dir = path.dirname(DB_PATH);
    if (!fs.existsSync(dir)) {
      fs.mkdirSync(dir, { recursive: true });
    }
    _db = new Database(DB_PATH);
    _db.pragma('journal_mode = WAL');
    _db.pragma('foreign_keys = ON');
    initSchema(_db);
  }
  return _db;
}

function initSchema(db: Database.Database): void {
  db.exec(`
    CREATE TABLE IF NOT EXISTS segments (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      description TEXT DEFAULT '',
      filters TEXT NOT NULL DEFAULT '[]',
      last_count INTEGER,
      last_extracted_at TEXT,
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS asset_library (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      image_url TEXT NOT NULL DEFAULT '',
      subject TEXT NOT NULL DEFAULT '',
      emoji TEXT DEFAULT '',
      preheader TEXT DEFAULT '',
      body_text TEXT DEFAULT '',
      tags TEXT DEFAULT '',
      marketo_email_id TEXT,
      marketo_program_id TEXT,
      marketo_folder_id INTEGER,
      reward_url_placeholder TEXT DEFAULT '{{REWARD_URL}}',
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS campaigns (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      segment_id TEXT NOT NULL,
      segment_name TEXT NOT NULL,
      asset_library_id TEXT NOT NULL,
      asset_name TEXT NOT NULL,
      reward_url TEXT NOT NULL DEFAULT '',
      scheduled_at TEXT NOT NULL,
      marketo_list_id TEXT,
      marketo_list_name TEXT,
      marketo_cloned_email_id TEXT,
      marketo_campaign_id TEXT,
      status TEXT NOT NULL DEFAULT 'draft',
      lead_count INTEGER DEFAULT 0,
      error_message TEXT,
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS job_logs (
      id TEXT PRIMARY KEY,
      campaign_id TEXT NOT NULL,
      step TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'pending',
      message TEXT,
      created_at TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS groups (
      id                   TEXT PRIMARY KEY,
      name                 TEXT NOT NULL,
      marketo_campaign_id  INTEGER NOT NULL,
      marketo_list_id      INTEGER NOT NULL,
      sort_order           INTEGER NOT NULL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS send_schedules (
      id                  TEXT PRIMARY KEY,
      group_id            TEXT NOT NULL,
      send_date           TEXT NOT NULL,
      marketo_email_id    INTEGER NOT NULL,
      marketo_email_name  TEXT NOT NULL DEFAULT '',
      send_time           TEXT NOT NULL DEFAULT '10:00',
      timezone            TEXT NOT NULL DEFAULT 'RTZ',
      status              TEXT NOT NULL DEFAULT 'draft',
      test_sent_at        TEXT,
      scheduled_at        TEXT,
      error_message       TEXT,
      created_at          TEXT NOT NULL,
      updated_at          TEXT NOT NULL,
      UNIQUE(group_id, send_date)
    );
  `);

  // ── 마이그레이션: 기존 DB에 컬럼 추가 (이미 존재하면 무시) ──────────
  const migrations: string[] = [
    // asset_library — Token 발송 모드
    `ALTER TABLE asset_library ADD COLUMN send_mode TEXT NOT NULL DEFAULT 'clone'`,
    `ALTER TABLE asset_library ADD COLUMN marketo_token_image TEXT DEFAULT ''`,
    `ALTER TABLE asset_library ADD COLUMN marketo_token_subject TEXT DEFAULT ''`,
    `ALTER TABLE asset_library ADD COLUMN marketo_token_preheader TEXT DEFAULT ''`,
    `ALTER TABLE asset_library ADD COLUMN marketo_token_body TEXT DEFAULT ''`,
    `ALTER TABLE asset_library ADD COLUMN marketo_token_emoji TEXT DEFAULT ''`,
    `ALTER TABLE asset_library ADD COLUMN marketo_token_reward_url TEXT DEFAULT ''`,
    // segments — Marketo Program 연결 (Static List 폴더 지정)
    `ALTER TABLE segments ADD COLUMN marketo_program_id TEXT DEFAULT ''`,
    // segments — Email Program Audience 고정 Static List
    `ALTER TABLE segments ADD COLUMN marketo_audience_list_id TEXT DEFAULT ''`,
    // campaigns — RTZ 발송 시각 (Phase 2에서 Email Program 스케줄에 사용)
    `ALTER TABLE campaigns ADD COLUMN send_time TEXT DEFAULT ''`,
    `ALTER TABLE segments ADD COLUMN is_recurring INTEGER DEFAULT 0`,
    `ALTER TABLE segments ADD COLUMN send_day_of_week INTEGER DEFAULT 1`,
    `ALTER TABLE segments ADD COLUMN recurring_send_time TEXT DEFAULT '10:00'`,
    `ALTER TABLE segments ADD COLUMN marketo_email_program_id TEXT DEFAULT ''`,
    `ALTER TABLE campaigns ADD COLUMN marketo_email_program_id TEXT`,
  ];
  for (const sql of migrations) {
    try { db.exec(sql); } catch { /* 이미 존재하는 컬럼 — 무시 */ }
  }

  // 기본 발송 그룹 시딩 — groups 테이블이 비어있을 때만 삽입
  const groupCount = (db.prepare('SELECT COUNT(*) AS c FROM groups').get() as { c: number }).c;
  if (groupCount === 0) {
    const seedGroups = [
      { id: 'active-a', name: 'Active A',  marketo_campaign_id: 7610, marketo_list_id: 8293, sort_order: 0 },
      { id: 'active-b', name: 'Active B',  marketo_campaign_id: 7611, marketo_list_id: 8294, sort_order: 1 },
      { id: 'fp-active', name: 'FP Active', marketo_campaign_id: 7613, marketo_list_id: 8296, sort_order: 2 },
      { id: 'np-active', name: 'NP Active', marketo_campaign_id: 7612, marketo_list_id: 8295, sort_order: 3 },
    ];
    const insert = db.prepare(
      `INSERT INTO groups (id, name, marketo_campaign_id, marketo_list_id, sort_order)
       VALUES (@id, @name, @marketo_campaign_id, @marketo_list_id, @sort_order)`
    );
    for (const g of seedGroups) insert.run(g);
  }
}
