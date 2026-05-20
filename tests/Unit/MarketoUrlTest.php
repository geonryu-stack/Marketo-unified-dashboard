<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../src/MarketoUrl.php';

final class MarketoUrlTest extends TestCase
{
    public function testParsesSmartCampaignSC(): void
    {
        $r = MarketoUrl::parse('https://app-528-HCC-317.marketo.com/#SC7610A1');
        $this->assertSame('smartCampaign', $r['type']);
        $this->assertSame(7610, $r['id']);
    }

    public function testParsesEmailProgramEP(): void
    {
        $r = MarketoUrl::parse('https://app-XXX.marketo.com/#EP7309A1');
        $this->assertSame('emailProgram', $r['type']);
        $this->assertSame(7309, $r['id']);
    }

    public function testParsesProgramPG(): void
    {
        $r = MarketoUrl::parse('https://app-XXX.marketo.com/#PG7309A1');
        $this->assertSame('program', $r['type']);
    }

    public function testParsesStaticListST(): void
    {
        $r = MarketoUrl::parse('https://app-XXX.marketo.com/#ST8293A1');
        $this->assertSame('staticList', $r['type']);
        $this->assertSame(8293, $r['id']);
    }

    public function testParsesEmailAssetEM(): void
    {
        $r = MarketoUrl::parse('https://app-XXX.marketo.com/#EM15510A1');
        $this->assertSame('email', $r['type']);
    }

    public function testReturnsNullForInvalidUrl(): void
    {
        $this->assertNull(MarketoUrl::parse('https://example.com/notmarketo'));
        $this->assertNull(MarketoUrl::parse(''));
    }

    public function testSuggestsCorrectColumn(): void
    {
        $this->assertSame('marketo_program_id',         MarketoUrl::suggestedColumn('program'));
        $this->assertSame('marketo_email_program_id',   MarketoUrl::suggestedColumn('emailProgram'));
        $this->assertSame('marketo_email_program_id',   MarketoUrl::suggestedColumn('smartCampaign'));
        $this->assertSame('marketo_audience_list_id',   MarketoUrl::suggestedColumn('staticList'));
        $this->assertSame('marketo_cloned_email_id',    MarketoUrl::suggestedColumn('email'));
    }
}
