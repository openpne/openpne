<?php

namespace Tests\Feature\Classic;

use App\Models\Diary;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Classic shell links the page module's OpenPNE 3 plugin stylesheet, in OpenPNE 3's cascade
 * order: skin, then the module's stylesheet, then the admin custom CSS that overrides both.
 * Which module gets which file is covered by Tests\Unit\Compat\PluginStylesheetsTest.
 */
class ClassicPluginStylesheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_links_the_module_stylesheet_between_the_skin_and_the_custom_css(): void
    {
        $this->setSnsSetting(SnsSettingKey::CustomCss, 'body{}');
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);

        $html = $this->actingAs($owner)->get("/diary/{$diary->getKey()}")->assertOk()->getContent();

        $skin = strpos($html, 'opSkinBasicPlugin/css/main.css');
        $plugin = strpos($html, 'opDiaryPlugin/css/diary.css');
        $custom = strpos($html, '/cache/css/customizing.css');

        $this->assertNotFalse($plugin);
        $this->assertGreaterThan($skin, $plugin);
        $this->assertGreaterThan($plugin, $custom);
    }

    public function test_a_screen_outside_those_modules_links_no_plugin_stylesheet(): void
    {
        // diary.css restyles .recentList / .commentList and message.css .prevNextLinkLine, kinds the
        // home page shares, so leaking either here would restyle a screen OpenPNE 3 left alone.
        $this->actingAs(Member::factory()->create())->get('/')
            ->assertOk()
            ->assertDontSee('opDiaryPlugin', false)
            ->assertDontSee('opCommunityTopicPlugin', false)
            ->assertDontSee('opMessagePlugin', false);
    }
}
