<?php
// api/groups.php — Post-S3 #2: 발송 그룹 프리셋 노출
// 세그먼트 생성 페이지에서 "발송 그룹" 셀렉트로 사용. ID 3종(Program/List/Email Program)
// 을 1클릭으로 자동 채울 수 있게 한다.
declare(strict_types=1);

try {
    $rows = DB::all(
        'SELECT id, name, marketo_program_id, marketo_campaign_id, marketo_list_id, marketo_email_program_id, sort_order
         FROM `groups` ORDER BY sort_order'
    );
    json_ok(['groups' => $rows]);
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}
