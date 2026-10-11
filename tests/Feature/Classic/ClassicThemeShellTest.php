<?php

namespace Tests\Feature\Classic;

use App\Models\Diary;
use App\Models\Member;
use App\Models\Navigation;
use App\Services\NavigationService;
use App\Support\SnsSettingKey;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * With a theme chosen the shell takes the theme plugin's template overrides: a navbar header holding
 * the local nav and one dropdown global nav, .well frames, the information label, the footer's
 * credit span.
 */
class ClassicThemeShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NavigationSeeder::class);
        $this->setSnsSetting(SnsSettingKey::ClassicTheme, 'cerulean');
    }

    public function test_the_header_is_a_navbar_with_the_local_nav_and_notification_center_inside_it(): void
    {
        $member = Member::factory()->create();

        $html = (string) $this->actingAs($member)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<div id="Header" class="navbar">', $html);
        $this->assertStringContainsString('<div id="HeaderContainer" class="navbar-inner">', $html);
        $this->assertStringNotContainsString('<h1 id="logo">', $html);
        $this->assertMatchesRegularExpression('/<div class="container">\s*<a href="[^"]+" class="brand">/', $html);
        $this->assertMatchesRegularExpression('/<ul class="nav" id="localNav">\s*<li id="notificationCenter">\s*<a [^>]*class="ncbutton ncbuttonLink"[^>]*>\s*<i class="fa fa-envelope-o"><\/i>\s*<i class="fa fa-users"><\/i>\s*<b>@<\/b>/', $html);
        $this->assertStringContainsString('<div id="notificationCenterDetail" class="alert alert-block">', $html);
        // The local nav items follow the center in the same list, with no ul.default of their own.
        $this->assertMatchesRegularExpression('/<\/li>\s*<li id="default__homepage">/', $html);
        $this->assertStringNotContainsString('<ul class="default">', $html);
        $this->assertStringNotContainsString('<div id="localNav">', $html);
        $this->assertStringContainsString('<span id="license"></span>', $html);
    }

    public function test_the_global_nav_is_one_dropdown_carrying_the_container_id_and_the_logout_form(): void
    {
        $member = Member::factory()->create();
        // A site that put the profile link in the global set, as OpenPNE 3's own default set did not.
        $mine = Navigation::create(['type' => 'secure_global', 'uri' => '/member/profile', 'source_uri' => '@member_profile_mine', 'sort_order' => 60]);
        $mine->setTranslation('en', 'Profile');
        app(NavigationService::class)->clearCache();

        $html = (string) $this->actingAs($member)->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div id="globalNav">\s*<ul class="nav pull-right">\s*<li id="globalNav" class="dropdown">\s*<a href="#" class="dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">\s*<p>menu<\/p><i class="fa fa-sort-asc"><\/i>\s*<\/a>\s*<ul class="dropdown-menu">\s*<li id="globalNav__homepage">/', $html);
        $this->assertMatchesRegularExpression('/<li id="globalNav__member_logout">\s*<form method="POST"/', $html);
        $this->assertStringContainsString('js/classic-theme-nav.js', $html);
        $this->assertStringContainsString('<noscript><style>#globalNav .dropdown-menu { display: block;', $html);
        // The profile item is captioned with the viewer's name, as the theme's _globalNav.php did.
        $this->assertStringContainsString('<li id="globalNav__member_profile_mine">', $html);
        $this->assertMatchesRegularExpression('/<li id="globalNav__member_profile_mine">\s*<a href="[^"]+">'.preg_quote(e($member->name), '/').'<\/a>/', $html);
    }

    public function test_viewing_another_member_inverts_the_navbar(): void
    {
        $viewer = Member::factory()->create();
        $other = Member::factory()->create();

        $html = (string) $this->actingAs($viewer)->get("/member/{$other->getKey()}")->assertOk()->getContent();

        $this->assertStringContainsString('<div id="Header" class="navbar navbar-inverse">', $html);
    }

    public function test_a_guest_page_keeps_the_empty_navbar_lists_and_the_login_fallback(): void
    {
        $html = (string) $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('<div id="Header" class="navbar">', $html);
        $this->assertMatchesRegularExpression('/<ul class="nav" id="localNav">\s*<\/ul>/', $html);
        $this->assertMatchesRegularExpression('/<ul class="nav pull-right">\s*<li id="globalNav_login">/', $html);
        $this->assertStringNotContainsString('class="dropdown"', $html);
    }

    public function test_parts_frames_take_the_well_class_and_the_information_box_its_label(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);

        $html = (string) $this->actingAs($owner)->get("/diary/{$diary->getKey()}")->assertOk()->getContent();

        // op_include_form / op_include_line frames take the theme's .well; the detail box and the comment list,
        // which OpenPNE 3's showSuccess.php and _list.php wrote by hand, keep .parts as they did there.
        $this->assertMatchesRegularExpression('/<div class="dparts form" id="formDiaryComment">\s*<div class="well">/', $html);
        $this->assertStringContainsString('<div class="well line" id="lineLinkToDiaryMemberList">', $html);
        $this->assertMatchesRegularExpression('/<div class="dparts diaryDetailBox" id="diary_show">\s*<div class="parts">/', $html);

        $box = Blade::render('<x-classic.parts id="information_9" name="informationBox"><div class="body sortHandle">text</div></x-classic.parts>');
        $this->assertMatchesRegularExpression('/<div class="well informationBox" id="information_9">\s*<div>Information<\/div>\s*<div class="body sortHandle">/', $box);
    }

    public function test_the_default_skin_shell_is_untouched_without_a_theme(): void
    {
        $this->setSnsSetting(SnsSettingKey::ClassicTheme, '');
        $member = Member::factory()->create();

        $html = (string) $this->actingAs($member)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<div id="Header">', $html);
        $this->assertStringContainsString('<h1 id="logo">', $html);
        $this->assertStringContainsString('<div id="localNav">', $html);
        $this->assertStringContainsString('<div id="notificationCenter">', $html);
        $this->assertStringNotContainsString('class="dropdown"', $html);
        $this->assertStringNotContainsString('id="license"', $html);
        $this->assertStringNotContainsString('<div class="well">', $html);
    }
}
