<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Classic shell reflects the OpenPNE 3 design settings: a custom-CSS <link> (only when set), the
 * PC HTML insertion slots at their fixed positions, and the footer chosen by the page's
 * secure/insecure class (OpenPNE 3 footer_after / footer_before), not the login state.
 */
class ClassicDesignRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_css_link_is_emitted_only_when_set(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('/cache/css/customizing.css', false);

        $this->setSnsSetting(SnsSettingKey::CustomCss, 'body{}');

        $this->get('/login')->assertOk()->assertSee('/cache/css/customizing.css', false);
    }

    public function test_html_insertion_slots_render_raw(): void
    {
        $this->setSnsSetting(SnsSettingKey::PcHtmlHead, '<meta name="op-head" content="1">');
        $this->setSnsSetting(SnsSettingKey::PcHtmlTop2, '<div id="op-top2"></div>');
        $this->setSnsSetting(SnsSettingKey::PcHtmlTop, '<div id="op-top"></div>');
        $this->setSnsSetting(SnsSettingKey::PcHtmlBottom2, '<div id="op-bottom2"></div>');
        $this->setSnsSetting(SnsSettingKey::PcHtmlBottom, '<div id="op-bottom"></div>');

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="op-head" content="1">', $html);
        $this->assertStringContainsString('<div id="op-top2"></div>', $html);
        $this->assertStringContainsString('<div id="op-top"></div>', $html);
        $this->assertStringContainsString('<div id="op-bottom2"></div>', $html);
        $this->assertStringContainsString('<div id="op-bottom"></div>', $html);
    }

    public function test_head_slot_is_in_head_and_top_slot_after_body(): void
    {
        $this->setSnsSetting(SnsSettingKey::PcHtmlHead, '<meta name="op-head" content="1">');
        $this->setSnsSetting(SnsSettingKey::PcHtmlTop2, '<i id="op-top2"></i>');

        $html = $this->get('/login')->assertOk()->getContent();

        $headEnd = strpos($html, '</head>');
        $bodyStart = strpos($html, '<body');

        $this->assertNotFalse($headEnd);
        $this->assertNotFalse($bodyStart);
        $this->assertLessThan($headEnd, strpos($html, 'op-head'));     // head slot is inside <head>
        $this->assertGreaterThan($bodyStart, strpos($html, 'op-top2')); // top2 slot is after <body>
    }

    public function test_footer_uses_insecure_html_for_a_guest(): void
    {
        $this->setSnsSetting(SnsSettingKey::FooterBefore, 'Guest footer text');
        $this->setSnsSetting(SnsSettingKey::FooterAfter, 'Member footer text');

        // /login is an insecure_page → footer_before.
        $this->get('/login')->assertOk()
            ->assertSee('Guest footer text', false)
            ->assertDontSee('Member footer text', false);
    }

    public function test_footer_uses_secure_html_for_a_member(): void
    {
        $this->setSnsSetting(SnsSettingKey::FooterBefore, 'Guest footer text');
        $this->setSnsSetting(SnsSettingKey::FooterAfter, 'Member footer text');

        // The member home is a secure_page → footer_after.
        $this->actingAs(Member::factory()->create())->get('/')->assertOk()
            ->assertSee('Member footer text', false)
            ->assertDontSee('Guest footer text', false);
    }
}
