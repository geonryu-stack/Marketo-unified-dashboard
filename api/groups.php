<?php
// api/groups.php — Post-S3 #2: 발송 그룹 프리셋 노출
declare(strict_types=1);

api_handle(function (string $method, ?string $id, ?string $action, array $params): void {
    $rows = DB::all(
        'SELECT id, name, marketo_program_id, marketo_campaign_id, marketo_list_id, marketo_email_program_id, sort_order
         FROM `groups` ORDER BY sort_order'
    );
    json_ok(['groups' => $rows]);
}, ['allowed_methods' => ['GET']]);
