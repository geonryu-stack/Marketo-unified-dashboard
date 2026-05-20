# scripts/ — 운영 자동화 스크립트

Sprint 2 INFRA (STRATEGY.md §5, HARNESS.md §E) 산출물.
"즉시 운영자가 손으로 돌릴 수 있고, 동시에 Task Scheduler/cron 에 그대로 붙는다" 가 설계 기준.

## backup_db.sh — 앱 DB 일일 백업

`marketo_automation` MySQL DB를 `mysqldump` → `gzip` 으로 `data/backups/YYYY-MM-DD_HHMM.sql.gz` 로 저장한다.
7일이 지난 백업은 자동으로 삭제 (`find ... -mtime +7 -delete`).

### 수동 실행

```bash
bash scripts/backup_db.sh
```

성공 시 stdout 예:

```
[backup_db] dumping marketo_automation@localhost:3306 → /path/to/data/backups/2026-05-20_0300.sql.gz
[backup_db] OK /path/to/data/backups/2026-05-20_0300.sql.gz (842 KB)
```

종료 코드: `0`=성공, `1`=의존성/설정 누락, `2`=mysqldump 실패.

### 의존성

| 도구 | 출처 |
|------|------|
| `mysqldump` | XAMPP 내장 (`C:\xampp\mysql\bin\mysqldump.exe`) — PATH 에 추가 필요 |
| `gzip` | macOS/Linux 기본. Windows 는 Git Bash 또는 GnuWin32 |
| `bash` | macOS/Linux 기본. Windows 는 **Git Bash** (XAMPP 와 무관) 권장 |

### 설정 우선순위

1. 환경변수 (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `DB_PORT`)
2. `config/config.php` 의 `define()` 값 (정규식 파싱 — PHP 없이 bash 만으로 추출)

## 자동 실행 등록

### Linux / macOS (cron)

매일 새벽 3시:

```cron
0 3 * * * /Users/geonwoo/marketo-send-automation/scripts/backup_db.sh >> /var/log/marketo_backup.log 2>&1
```

또는 `crontab -e` 후 추가. `>> .log 2>&1` 로 출력을 파일에 누적해두면 사후 추적에 유용.

### Windows (Task Scheduler)

**GUI 등록**: 작업 스케줄러 열기 → 작업 만들기 →
- 트리거: 매일 03:00
- 작업: 프로그램/스크립트 `C:\Program Files\Git\bin\bash.exe`
- 인수: `C:\xampp\htdocs\marketo-automation\scripts\backup_db.sh`
- 시작 위치: `C:\xampp\htdocs\marketo-automation`

**CLI 등록 (관리자 권한 PowerShell)**:

```powershell
schtasks /create `
  /tn "MarketoAutomation\DailyBackup" `
  /sc daily /st 03:00 `
  /tr "\"C:\Program Files\Git\bin\bash.exe\" C:\xampp\htdocs\marketo-automation\scripts\backup_db.sh" `
  /rl HIGHEST
```

확인:

```powershell
schtasks /query /tn "MarketoAutomation\DailyBackup"
```

삭제:

```powershell
schtasks /delete /tn "MarketoAutomation\DailyBackup" /f
```

### 백업 복구 (참고)

```bash
gunzip -c data/backups/2026-05-20_0300.sql.gz | \
  mysql --host=localhost --user=root --password=YOUR_PASS marketo_automation
```

위 명령은 **데이터를 덮어씁니다**. 복구 직전 현 DB 를 한 번 더 백업하거나, 임시 DB 로 import 후 비교 권장.

## 향후 (Sprint 3+)

- 사내 DB 스키마 드리프트 자동 검출 cron (`STRATEGY ④`)
- 백업 파일을 S3/Backblaze 등 외부 저장소로 push (현재는 로컬만)
- 백업 실패 시 Slack 알림 — `Notifier::slack(... 'critical')` 연계 (현재는 stderr/exit code 만)
