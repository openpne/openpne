<?php

declare(strict_types=1);

namespace Tests\Feature\Classic;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShellNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_secure_page_renders_the_default_local_nav(): void
    {
        $this->seed(NavigationSeeder::class);
        $member = Member::factory()->create();

        // ids carry OpenPNE 3's op_url_to_id(source_uri) so custom CSS keeps matching.
        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('<ul class="default">', false)
            ->assertSee('id="default__homepage"', false)
            ->assertSee('id="default__friend_list"', false)
            ->assertSee('id="default_diary_listMember"', false)
            ->assertSee('id="default__member_profile_mine"', false)
            ->assertSee('id="default__member_editProfile"', false)
            ->assertDontSee('%my_friend%', false); // term layer resolved the caption
    }

    public function test_secure_global_nav_uses_openpne3_ids_and_hides_unreachable_items(): void
    {
        $this->seed(NavigationSeeder::class);
        $member = Member::factory()->create();

        $html = $this->actingAs($member)->get('/')->assertOk()
            ->assertSee('id="globalNav__homepage"', false)
            ->assertSee('id="globalNav__member_search"', false)
            ->assertSee('id="globalNav__member_invite"', false)
            ->assertSee('id="globalNav__member_logout"', false)
            // /member/config is now a real page (the member settings page), so it renders.
            ->assertSee('id="globalNav__member_config"', false)
            // /diary index is still unported — hidden by the renderer's route check.
            ->assertDontSee('id="globalNav_diary_index"', false)
            ->getContent();

        // logout is GET-unreachable in OpenPNE 4, so it renders as a POST form button.
        $this->assertStringContainsString('<form method="POST" action="'.route('logout').'"', $html);
    }

    public function test_guest_on_a_classic_page_does_not_see_the_local_nav(): void
    {
        // localNav is secure-only in OpenPNE 3; a web-public profile reaches the
        // Classic shell as a guest, so the hook is present but unpopulated.
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $profile = Profile::factory()->create(['is_public_web' => true]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => 'public-value', 'visibility' => Visibility::Open,
        ]);

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('id="localNav"', false)
            ->assertDontSee('<ul class="default">', false);
    }

    public function test_footer_renders_the_configured_html(): void
    {
        // A logged-in member is on a secure page, so the footer shows footer_after (OpenPNE 3 parity).
        $this->setSnsSetting(SnsSettingKey::FooterAfter, 'Operated by <a href="https://example.test">Example</a>');
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('Operated by <a href="https://example.test">Example</a>', false);
    }

    public function test_footer_paragraph_is_omitted_when_no_html_is_configured(): void
    {
        $this->setSnsSetting(SnsSettingKey::FooterAfter, '');
        $member = Member::factory()->create();

        $html = $this->actingAs($member)->get('/')->assertOk()->getContent();
        $footer = Str::between($html, '<div id="FooterContainer">', '</div>');

        $this->assertStringNotContainsString('<p>', $footer);
    }
}
