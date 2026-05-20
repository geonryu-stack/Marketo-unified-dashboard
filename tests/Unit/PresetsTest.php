<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Sprint 3 ASSET — validate_preset_input() 입력 검증 단위 테스트.
 *
 * 본 함수는 api/content-presets.php의 POST 경로에서 호출된다.
 * DB 의존이 없는 순수 함수이므로 PHPUnit으로 직접 검증 가능.
 */
require_once __DIR__ . '/../../src/Presets.php';

final class PresetsTest extends TestCase
{
    public function testRejectsEmptyLabel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/label/');
        validate_preset_input([
            'label'              => '',
            'emoji'              => '🎁',
            'title_template'     => 'x',
            'preheader_template' => 'y',
        ]);
    }

    public function testRejectsWhitespaceOnlyLabel(): void
    {
        // trim 후 빈 문자열도 거부되어야 한다.
        $this->expectException(InvalidArgumentException::class);
        validate_preset_input([
            'label' => "   \t\n  ",
        ]);
    }

    public function testAcceptsValidInputAndNormalizes(): void
    {
        $out = validate_preset_input([
            'label'              => '  🎁 기본 보상  ',
            'emoji'              => '  🎁  ',
            'title_template'     => '  보상이 도착했어요  ',
            'preheader_template' => '  오늘만 확인 가능  ',
        ]);

        $this->assertSame('🎁 기본 보상',        $out['label']);
        $this->assertSame('🎁',                   $out['emoji']);
        $this->assertSame('보상이 도착했어요',     $out['title_template']);
        $this->assertSame('오늘만 확인 가능',     $out['preheader_template']);
    }

    public function testAcceptsLegacyTitleKey(): void
    {
        // 클라이언트가 'title'/'preheader' 짧은 키로 보내도 받아야 한다 (JS 상수와 명명 호환).
        $out = validate_preset_input([
            'label'     => '레거시 키',
            'emoji'     => '✨',
            'title'     => 'legacy-title',
            'preheader' => 'legacy-preheader',
        ]);

        $this->assertSame('legacy-title',     $out['title_template']);
        $this->assertSame('legacy-preheader', $out['preheader_template']);
    }

    public function testRejectsOversizedLabel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        validate_preset_input([
            'label' => str_repeat('a', 256),
        ]);
    }

    public function testRejectsOversizedEmoji(): void
    {
        $this->expectException(InvalidArgumentException::class);
        validate_preset_input([
            'label' => 'ok',
            // VARCHAR(20) — 21자 거부
            'emoji' => str_repeat('a', 21),
        ]);
    }
}
