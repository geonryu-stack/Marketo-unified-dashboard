#!/usr/bin/env bash
# scripts/backup_db.sh — Sprint 2 INFRA (HARNESS §E / STRATEGY ㉔ 자동 백업/롤백)
#
# marketo_automation 앱 DB를 mysqldump → gzip 으로 data/backups/ 에 저장.
# 7일 보존 정책. cron / Windows Task Scheduler 에서 매일 1회 호출 가정.
#
# 의존성:
#   - mysqldump (XAMPP MySQL 번들 포함)
#   - gzip (macOS/Linux 기본)
#   - config/config.php — DB_HOST / DB_PORT / DB_USER / DB_PASS / DB_NAME 정의값을 사용
#
# 사용:
#   bash scripts/backup_db.sh            # 프로덕션 모드 (config 값 사용)
#   DB_NAME=other_db bash backup_db.sh   # 환경변수로 override 가능
#
# 종료 코드:
#   0 = 성공
#   1 = 의존성/설정 누락
#   2 = mysqldump 실패

set -euo pipefail

# ── 경로 산정 ──────────────────────────────────────────────────────
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$( cd "${SCRIPT_DIR}/.." && pwd )"
CONFIG_FILE="${PROJECT_ROOT}/config/config.php"
BACKUP_DIR="${PROJECT_ROOT}/data/backups"

# ── 의존성 확인 ────────────────────────────────────────────────────
if ! command -v mysqldump >/dev/null 2>&1; then
  echo "[backup_db] ERROR: mysqldump not found in PATH. XAMPP의 mysql/bin 을 PATH에 추가하세요." >&2
  exit 1
fi
if ! command -v gzip >/dev/null 2>&1; then
  echo "[backup_db] ERROR: gzip not found." >&2
  exit 1
fi

# ── config/config.php 에서 DB 접속 정보 파싱 (define 라인) ─────────
# PHP 실행 없이 정규식만으로 추출 — bash 단독 동작 보장.
get_define() {
  local key="$1"
  # define('KEY', 'value') 또는 define("KEY", "value") 또는 숫자 값.
  # 가장 단순한 형태만 지원 (config.example.php 와 같은 포맷).
  awk -v k="$key" '
    match($0, /define\(\s*['\''"]([A-Z_]+)['\''"]\s*,\s*['\''"](.*)['\''"]\s*\)/, m) {
      if (m[1] == k) { print m[2]; exit }
    }
    match($0, /define\(\s*['\''"]([A-Z_]+)['\''"]\s*,\s*([0-9]+)\s*\)/, n) {
      if (n[1] == k) { print n[2]; exit }
    }
  ' "$CONFIG_FILE"
}

if [[ ! -f "$CONFIG_FILE" ]]; then
  echo "[backup_db] ERROR: config 파일 없음: $CONFIG_FILE" >&2
  exit 1
fi

# 환경변수 우선, 없으면 config 파일에서 추출.
DB_HOST="${DB_HOST:-$(get_define DB_HOST)}"
DB_PORT="${DB_PORT:-$(get_define DB_PORT)}"
DB_USER="${DB_USER:-$(get_define DB_USER)}"
DB_PASS="${DB_PASS:-$(get_define DB_PASS)}"
DB_NAME="${DB_NAME:-$(get_define DB_NAME)}"

if [[ -z "${DB_HOST}" || -z "${DB_NAME}" || -z "${DB_USER}" ]]; then
  echo "[backup_db] ERROR: DB 접속 정보 누락 (HOST/USER/NAME). config/config.php 확인 필요." >&2
  exit 1
fi

# ── 백업 디렉터리 보장 ────────────────────────────────────────────
mkdir -p "$BACKUP_DIR"

# ── 파일명: YYYY-MM-DD_HHMM.sql.gz ─────────────────────────────────
STAMP="$(date +"%Y-%m-%d_%H%M")"
OUT_FILE="${BACKUP_DIR}/${STAMP}.sql.gz"

echo "[backup_db] dumping ${DB_NAME}@${DB_HOST}:${DB_PORT} → ${OUT_FILE}"

# ── mysqldump 실행 + gzip 압축 ────────────────────────────────────
# --single-transaction : InnoDB 정합성 (lock 없이 일관 스냅샷)
# --routines / --triggers : 저장 프로시저/트리거 포함
# 비밀번호가 비어 있어도 안전 (-p"" 가 작동)
set +e
if [[ -z "${DB_PASS}" ]]; then
  mysqldump \
    --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USER}" \
    --single-transaction --routines --triggers --default-character-set=utf8mb4 \
    "${DB_NAME}" | gzip > "${OUT_FILE}"
  DUMP_STATUS=${PIPESTATUS[0]}
else
  mysqldump \
    --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USER}" --password="${DB_PASS}" \
    --single-transaction --routines --triggers --default-character-set=utf8mb4 \
    "${DB_NAME}" | gzip > "${OUT_FILE}"
  DUMP_STATUS=${PIPESTATUS[0]}
fi
set -e

if [[ "${DUMP_STATUS}" -ne 0 ]]; then
  echo "[backup_db] ERROR: mysqldump 실패 (exit=${DUMP_STATUS}). 부분 파일 제거." >&2
  rm -f "${OUT_FILE}"
  exit 2
fi

SIZE_KB=$(du -k "${OUT_FILE}" | awk '{print $1}')
echo "[backup_db] OK ${OUT_FILE} (${SIZE_KB} KB)"

# ── 7일 보존 정책: 8일 이상된 백업 자동 삭제 ───────────────────────
DELETED=$(find "${BACKUP_DIR}" -maxdepth 1 -name "*.sql.gz" -mtime +7 -print -delete | wc -l | tr -d ' ')
if [[ "${DELETED}" -gt 0 ]]; then
  echo "[backup_db] pruned ${DELETED} backup(s) older than 7 days"
fi

exit 0
