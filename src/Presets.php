<?php
// src/Presets.php
//
// Sprint 3 ASSET — 콘텐츠 프리셋 입력 검증 (순수 함수).
// 본 함수는 DB 의존이 없어 PHPUnit으로 직접 검증한다.
declare(strict_types=1);

if (!function_exists('validate_preset_input')) {
    /**
     * POST /api/content-presets 의 body를 검증/정규화한다.
     *
     * 규칙:
     *  - label: 필수. trim 후 빈 문자 거부. 최대 255자.
     *  - emoji: 선택. trim. 최대 20자(스키마 VARCHAR(20)).
     *  - title_template: 선택. trim. 최대 500자.
     *  - preheader_template: 선택. trim. 최대 500자.
     *
     * @param  array $body
     * @return array {label, emoji, title_template, preheader_template}
     * @throws InvalidArgumentException 검증 실패 시 (api/content-presets.php가 400으로 매핑)
     */
    function validate_preset_input(array $body): array
    {
        $label = trim((string)($body['label'] ?? ''));
        if ($label === '') {
            throw new InvalidArgumentException('label은 필수입니다.');
        }
        if (mb_strlen($label, 'UTF-8') > 255) {
            throw new InvalidArgumentException('label은 최대 255자입니다.');
        }

        $emoji = trim((string)($body['emoji'] ?? ''));
        if (mb_strlen($emoji, 'UTF-8') > 20) {
            throw new InvalidArgumentException('emoji는 최대 20자입니다.');
        }

        // 클라이언트는 'title' / 'title_template' 둘 다 보낼 수 있음 (campaign.js의 명명 일관성 유지).
        $title = trim((string)(
            $body['title_template'] ?? $body['title'] ?? ''
        ));
        if (mb_strlen($title, 'UTF-8') > 500) {
            throw new InvalidArgumentException('title_template은 최대 500자입니다.');
        }

        $preheader = trim((string)(
            $body['preheader_template'] ?? $body['preheader'] ?? ''
        ));
        if (mb_strlen($preheader, 'UTF-8') > 500) {
            throw new InvalidArgumentException('preheader_template은 최대 500자입니다.');
        }

        return [
            'label'              => $label,
            'emoji'              => $emoji,
            'title_template'     => $title,
            'preheader_template' => $preheader,
        ];
    }
}
