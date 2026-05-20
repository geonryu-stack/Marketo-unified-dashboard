<?php
// src/MarketoUrl.php
// 운영자가 Marketo UI 주소창의 URL을 붙여넣으면 객체 종류 + ID를 자동 식별.
// 비전문가 운영자가 ID 종류(Program/Email Program/Smart Campaign/List/Email) 를
// 헷갈리지 않도록 입력 단계에서 자동 매핑.
declare(strict_types=1);

class MarketoUrl
{
    /**
     * Marketo URL 안의 #PG/EP/SC/CA/ST/LI/EM/ML 코드를 해석한다.
     *
     * @param string $url 예) "https://app-528-HCC-317.marketo.com/#SC7610A1"
     * @return array{type:string,id:int,label:string}|null
     *   type: 'program' | 'emailProgram' | 'smartCampaign' | 'staticList' | 'email' | 'folder'
     *   id:   객체 ID (정수)
     *   label: 운영자에게 보여줄 한글 라벨
     *   파싱 실패 시 null
     */
    public static function parse(string $url): ?array
    {
        // URL 어디든 #XX12345 형태가 있으면 매칭. 'A1' suffix는 옵션.
        if (!preg_match('/#([A-Z]{2})(\d+)[A-Z0-9]*/i', $url, $m)) {
            return null;
        }
        $code = strtoupper($m[1]);
        $id   = (int)$m[2];
        if ($id <= 0) return null;

        $map = [
            'PG' => ['program',       'Program (콘텐츠 폴더)'],
            'EP' => ['emailProgram',  'Email Program (배치 발송 관리)'],
            'SC' => ['smartCampaign', 'Smart Campaign (조건 기반 발송)'],
            'CA' => ['smartCampaign', 'Smart Campaign (조건 기반 발송)'],
            'ST' => ['staticList',    'Static List (대상자 리스트)'],
            'LI' => ['staticList',    'Static List (대상자 리스트)'],
            'EM' => ['email',         'Email Asset (이메일 콘텐츠)'],
            'ML' => ['email',         'Email Asset (이메일 콘텐츠)'],
            'FO' => ['folder',        'Folder (폴더)'],
        ];
        if (!isset($map[$code])) {
            return ['type' => 'unknown', 'id' => $id, 'label' => "알 수 없는 코드: #{$code}{$id}"];
        }
        return ['type' => $map[$code][0], 'id' => $id, 'label' => $map[$code][1]];
    }

    /**
     * 현재 시스템 컬럼명 매핑.
     * URL을 파싱한 결과에서 어떤 segments 컬럼에 넣어야 하는지 알려준다.
     */
    public static function suggestedColumn(string $type): ?string
    {
        return [
            'program'       => 'marketo_program_id',
            'emailProgram'  => 'marketo_email_program_id',
            'smartCampaign' => 'marketo_email_program_id', // 현 코드 — 운영 실측 후 별도 컬럼으로 분리 가능
            'staticList'    => 'marketo_audience_list_id',
            'email'         => 'marketo_cloned_email_id',
            'folder'        => null,
        ][$type] ?? null;
    }
}
