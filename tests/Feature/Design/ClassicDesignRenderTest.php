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

        // An explicitly-empty value counts as "no custom CSS": the presence check is an existence
        // query for a non-empty row, so no <link> is emitted.
        $this->setSnsSetting(SnsSettingKey::CustomCss, '');

        $this->get('/login')->assertOk()->assertDontSee('/cache/css/customizing.css', false);
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

    /** @return array<string, string> slot key => the operator HTML, script and iframe included, as sites store it */
    private function activeHtmlBySlot(): array
    {
        $html = [];
        foreach (['head', 'top2', 'top', 'bottom2', 'bottom', 'footer'] as $slot) {
            $html[$slot] = '<script>window.opSlot_'.$slot.' = 1;</script><iframe src="https://example.com/'.$slot.'" title="'.$slot.'"></iframe>';
        }

        return $html;
    }

    public function test_slots_and_footer_pass_script_and_iframe_through_unescaped(): void
    {
        $html = $this->activeHtmlBySlot();
        $this->setSnsSetting(SnsSettingKey::PcHtmlHead, $html['head']);
        $this->setSnsSetting(SnsSettingKey::PcHtmlTop2, $html['top2']);
        $this->setSnsSetting(SnsSettingKey::PcHtmlTop, $html['top']);
        $this->setSnsSetting(SnsSettingKey::PcHtmlBottom2, $html['bottom2']);
        $this->setSnsSetting(SnsSettingKey::PcHtmlBottom, $html['bottom']);
        $this->setSnsSetting(SnsSettingKey::FooterBefore, $html['footer']);
        $this->setSnsSetting(SnsSettingKey::FooterAfter, $html['footer']);

        $guest = $this->get('/login')->assertOk()->getContent();
        $member = $this->actingAs(Member::factory()->create())->get('/')->assertOk()->getContent();

        foreach ($html as $slot => $fragment) {
            $this->assertStringContainsString($fragment, $guest, "{$slot} on a guest page");
            $this->assertStringContainsString($fragment, $member, "{$slot} on a member page");
        }
        $this->assertStringNotContainsString('&lt;script&gt;', $guest);
        $this->assertStringNotContainsString('&lt;script&gt;', $member);
    }

    public function test_modern_pages_emit_none_of_the_classic_slots(): void
    {
        $html = $this->activeHtmlBySlot();
        $this->setSnsSetting(SnsSettingKey::PcHtmlHead, $html['head']);
        $this->setSnsSetting(SnsSettingKey::PcHtmlTop2, $html['top2']);
        $this->setSnsSetting(SnsSettingKey::PcHtmlTop, $html['top']);
        $this->setSnsSetting(SnsSettingKey::PcHtmlBottom2, $html['bottom2']);
        $this->setSnsSetting(SnsSettingKey::PcHtmlBottom, $html['bottom']);
        $this->setSnsSetting(SnsSettingKey::FooterBefore, $html['footer']);
        $this->setSnsSetting(SnsSettingKey::FooterAfter, $html['footer']);
        config()->set('openpne.surface_mode', 'modern_default');

        $guest = $this->get('/login')->assertOk()->getContent();
        $member = $this->actingAs(Member::factory()->create())->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('opSlot_', $guest);
        $this->assertStringNotContainsString('example.com/', $guest);
        $this->assertStringNotContainsString('opSlot_', $member);
        $this->assertStringNotContainsString('example.com/', $member);
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
