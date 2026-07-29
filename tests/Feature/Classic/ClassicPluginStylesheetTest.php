<?php

namespace Tests\Feature\Classic;

use App\Models\Community;
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

    public function test_the_community_home_pushes_the_component_stylesheet_into_the_cascade(): void
    {
        // OpenPNE 3 loads communityTopic.css on community home from the embedded list components'
        // addStylesheet, not the community module's view.yml — the screen pushes the link itself,
        // into the same cascade slot: after the skin, before the admin custom CSS.
        $this->setSnsSetting(SnsSettingKey::CustomCss, 'body{}');
        $community = Community::factory()->create();

        $html = $this->actingAs(Member::factory()->create())
            ->get(route('community.show', $community))->assertOk()->getContent();

        $skin = strpos($html, 'opSkinBasicPlugin/css/main.css');
        $plugin = strpos($html, 'opCommunityTopicPlugin/css/communityTopic.css');
        $custom = strpos($html, '/cache/css/customizing.css');

        $this->assertNotFalse($plugin);
        $this->assertGreaterThan($skin, $plugin);
        $this->assertGreaterThan($plugin, $custom);
    }

    public function test_the_component_stylesheet_follows_the_screen_not_the_community_module(): void
    {
        // OpenPNE 3 loaded communityTopic.css on the community home only; the module's other
        // screens declare no stylesheet.
        $this->actingAs(Member::factory()->create())->get(route('community.search'))
            ->assertOk()
            ->assertDontSee('opCommunityTopicPlugin', false);
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
