<?php
// src/helpers/logging.php — cron/잡 로그 + DRY_RUN + run_id + 스크린샷 + status_history
declare(strict_types=1);

function job_log(
    string $message,
    ?string $campaign_id = null,
    string $step = 'cron',
    string $status = 'info',
    ?string $run_id = null
): void {
    if (defined('RUNNING_AS_CLI') && RUNNING_AS_CLI) {
        $prefix = $run_id !== null ? '[run:' . substr($run_id, 0, 8) . '] ' : '';
        echo '[' . date('Y-m-d H:i:s') . '] ' . $prefix . $message . PHP_EOL;
    }
    if ($campaign_id !== null) {
        DB::exec(
            'INSERT INTO job_logs (id, campaign_id, step, status, run_id, message, created_at) VALUES (?,?,?,?,?,?,?)',
            [new_uuid(), $campaign_id, $step, $status, $run_id, $message, now_str()]
        );
    }
}

function is_dry_run(): bool
{
    return defined('DRY_RUN_MODE') && DRY_RUN_MODE === true;
}

function ensure_run_id(array $campaign): string
{
    $existing = $campaign['run_id'] ?? null;
    if (is_string($existing) && $existing !== '') {
        return $existing;
    }
    $id = $campaign['id'] ?? null;
    if (!is_string($id) || $id === '') {
        throw new RuntimeException('ensure_run_id: campaign["id"] 가 비어있습니다.');
    }
    $new = new_uuid();
    DB::exec('UPDATE campaigns SET run_id=? WHERE id=?', [$new, $id]);
    return $new;
}

// ── 스크린샷 첨부 저장소 ─────────────────────────────────────────

const SCREENSHOT_MAX_BYTES        = 5 * 1024 * 1024; // 5MB
const SCREENSHOT_ALLOWED_EXT      = ['jpg', 'jpeg', 'png', 'webp'];
const SCREENSHOT_ALLOWED_MIME     = ['image/jpeg', 'image/png', 'image/webp'];
const SCREENSHOT_STORAGE_SUBDIR   = 'data/screenshots';

function screenshot_save(string $tmp_path, string $campaign_id, string $original_name): string
{
    if ($tmp_path === '' || !is_file($tmp_path)) {
        throw new RuntimeException('screenshot_save: 업로드 파일이 존재하지 않습니다.');
    }

    $size = @filesize($tmp_path);
    if ($size === false) {
        throw new RuntimeException('screenshot_save: 파일 크기를 읽을 수 없습니다.');
    }
    if ($size > SCREENSHOT_MAX_BYTES) {
        throw new RuntimeException(sprintf(
            'screenshot_save: 파일 크기 초과(%d bytes, 한도 %d bytes)',
            $size, SCREENSHOT_MAX_BYTES
        ));
    }

    $ext = strtolower((string)pathinfo($original_name, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, SCREENSHOT_ALLOWED_EXT, true)) {
        throw new RuntimeException(sprintf(
            'screenshot_save: 허용되지 않은 확장자 "%s" (허용: %s)',
            $ext, implode(',', SCREENSHOT_ALLOWED_EXT)
        ));
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($tmp_path);
        if ($mime !== false && $mime !== null && !in_array((string)$mime, SCREENSHOT_ALLOWED_MIME, true)) {
            throw new RuntimeException(sprintf(
                'screenshot_save: 허용되지 않은 MIME "%s"', (string)$mime
            ));
        }
    }

    $safe_camp = preg_replace('/[^A-Za-z0-9._-]/', '_', $campaign_id);
    if ($safe_camp === '' || $safe_camp === null) {
        throw new RuntimeException('screenshot_save: campaign_id 가 비어있거나 유효하지 않습니다.');
    }

    $project_root = dirname(__DIR__, 2);
    $rel_dir      = SCREENSHOT_STORAGE_SUBDIR . '/' . $safe_camp;
    $abs_dir      = $project_root . '/' . $rel_dir;

    if (!is_dir($abs_dir)) {
        if (!@mkdir($abs_dir, 0775, true) && !is_dir($abs_dir)) {
            throw new RuntimeException('screenshot_save: 저장 디렉터리 생성 실패: ' . $abs_dir);
        }
    }

    $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $original_name);
    if ($safe_name === '' || $safe_name === null) {
        $safe_name = 'screenshot.' . $ext;
    }
    $filename = date('Ymd_His') . '_' . $safe_name;
    $rel_path = $rel_dir . '/' . $filename;
    $abs_path = $abs_dir . '/' . $filename;

    $moved = false;
    if (is_uploaded_file($tmp_path)) {
        $moved = @move_uploaded_file($tmp_path, $abs_path);
    }
    if (!$moved) {
        $moved = @copy($tmp_path, $abs_path);
    }
    if (!$moved) {
        throw new RuntimeException('screenshot_save: 파일 저장 실패: ' . $abs_path);
    }

    @chmod($abs_path, 0644);

    return $rel_path;
}

// ── status_history 적재 ─────────────────────────────────────────

function record_status_transition(
    string $campaign_id,
    ?string $from,
    string $to,
    string $actor = 'system',
    ?string $notes = null,
    ?string $run_id = null
): void {
    DB::exec(
        'INSERT INTO status_history (id, campaign_id, from_status, to_status, actor, notes, run_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [new_uuid(), $campaign_id, $from, $to, $actor, $notes, $run_id, now_str()]
    );
}
