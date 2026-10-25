<?php

namespace Tests\Feature\Classic;

use App\Models\Diary;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** With a theme chosen, OpenPNE 3 dropped the default skin and added the theme's stylesheets last. */
class ClassicThemeStylesheetTest extends TestCase
{
    use RefreshDatabase;

    private function diaryPage(): string
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);

        return (string) $this->actingAs($owner)->get("/diary/{$diary->getKey()}")->assertOk()->getContent();
    }

    public function test_a_theme_replaces_the_default_skin_and_is_linked_after_the_module_and_custom_css(): void
    {
        $this->setSnsSetting(SnsSettingKey::CustomCss, 'body{}');
        $this->setSnsSetting(SnsSettingKey::ClassicTheme, 'cerulean');

        $html = $this->diaryPage();

        $this->assertStringNotContainsString('opSkinBasicPlugin/css/main.css', $html);
        $positions = array_map(
            fn (string $needle): int|false => strpos($html, $needle),
            ['opDiaryPlugin/css/diary.css', '/cache/css/customizing.css', 'opSkinThemePlugin/cerulean/css/bootstrap.css', 'opSkinThemePlugin/cerulean/css/font-awesome.css', 'opSkinThemePlugin/cerulean/css/foundation.css', 'opSkinThemePlugin/cerulean/css/main.css'],
        );
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'theme stylesheets follow the module and custom CSS, in name order');
    }

    public function test_the_default_skin_stays_when_no_theme_is_chosen_or_the_chosen_one_is_not_on_disk(): void
    {
        $this->assertStringContainsString('opSkinBasicPlugin/css/main.css', $this->diaryPage());
        $this->assertStringNotContainsString('opSkinThemePlugin/', $this->diaryPage());

        $this->setSnsSetting(SnsSettingKey::ClassicTheme, 'no-such-theme');

        $html = $this->diaryPage();
        $this->assertStringContainsString('opSkinBasicPlugin/css/main.css', $html);
        $this->assertStringNotContainsString('opSkinThemePlugin/', $html);
    }

    public function test_the_themed_footer_opens_with_the_empty_license_span_of_the_theme_plugin_footer(): void
    {
        $this->assertStringNotContainsString('id="license"', $this->diaryPage());

        $this->setSnsSetting(SnsSettingKey::ClassicTheme, 'cerulean');
        $this->assertStringContainsString('<span id="license"></span>', $this->diaryPage());
    }
}
