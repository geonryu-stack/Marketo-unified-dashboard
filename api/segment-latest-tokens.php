<?php
// api/segment-latest-tokens.php — Post-S3 #3
// 새 캠페인 페이지에서 segment 선택 시 직전 sent 회차의 토큰 4종을 자동 채움.
declare(strict_types=1);

api_handle(function (string $method, ?string $id, ?string $action, array $params): void {
    $segment_id = $params['id'] ?? '';
    if ($segment_id === '') {
        json_err('segment id 필요', 400);
    }

    $row = DB::one(
        "SELECT id, name, send_time, emoji, email_title, email_preheader, reward_url
         FROM campaigns
         WHERE segment_id = ? AND status = 'sent'
         ORDER BY send_time DESC, created_at DESC
         LIMIT 1",
        [$segment_id]
    );
    if (!$row) {
        json_ok(['latest' => null]);
    } else {
        json_ok([
            'latest' => [
                'campaign_id' => $row['id'],
                'campaign_name' => $row['name'],
                'send_time'   => $row['send_time'],
                'emoji'       => $row['emoji'] ?? '',
                'title'       => $row['email_title'] ?? '',
                'preheader'   => $row['email_preheader'] ?? '',
                'reward_url'  => $row['reward_url'] ?? '',
            ],
        ]);
    }
}, ['allowed_methods' => ['GET']]);
