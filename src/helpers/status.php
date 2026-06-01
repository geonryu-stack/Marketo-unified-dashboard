<?php
// src/helpers/status.php — 캠페인 상태 한국어 레이블 + 뱃지 CSS 클래스
declare(strict_types=1);

function status_label(string $status): string
{
    return [
        'draft'               => '초안',
        'awaiting_approval'   => '결재 대기',
        'scheduling'          => '예약 설정 중',
        'bulk_polling'        => '대용량 업로드 중',
        'bulk_finalizing'    => 'EP 예약 진행 중',
        'scheduled'           => '예약 완료',
        'sent'                => '발송 완료',
        'needs_manual_review' => '수동 검토 필요',
        'failed'              => '실패',
        'test_sent'           => '테스트 발송 완료',
    ][$status] ?? $status;
}

function status_badge_class(string $status): string
{
    return [
        'draft'               => 'secondary',
        'awaiting_approval'   => 'warning',
        'scheduling'          => 'warning',
        'bulk_polling'        => 'warning',
        'bulk_finalizing'    => 'warning',
        'scheduled'           => 'success',
        'sent'                => 'success',
        'needs_manual_review' => 'danger',
        'failed'              => 'danger',
        'test_sent'           => 'info',
    ][$status] ?? 'secondary';
}
