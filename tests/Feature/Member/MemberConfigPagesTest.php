<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class MemberConfigPagesTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/member/config')->assertRedirect('/login');
    }

    public function test_the_classic_landing_shows_the_category_nav_and_no_form(): void
    {
        // OpenPNE 3 member/config with no ?category=: LayoutB, the category pageNav, and the
        // "select an item" box — no section form yet.
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config')
            ->assertOk()
            ->assertSee('id="page_member_config"', false)
            ->assertSee('id="LayoutB"', false)
            ->assertSee('id="Left"', false)
            ->assertSee('class="dparts pageNav"', false)
            ->assertSee('Please select the item')
            ->assertDontSee('id="diaryForm"', false)
            ->assertDontSee('id="generalForm"', false);
    }

    public function test_the_category_nav_links_to_the_other_categories(): void
    {
        $member = Member::factory()->create();
        Profile::factory()->preset('birthday')->create(['form_type' => 'date']); // offers the age category

        // On the diary page, diary is plain text and the other three are links.
        $this->actingAs($member)->get('/member/config?category=diary')
            ->assertOk()
            ->assertSee('id="LayoutB"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'publicFlag']).'"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'language']).'"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'general']).'"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'password']).'"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'email']).'"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'withdrawal']).'"', false)
            ->assertDontSee('href="'.route('member.config', ['category' => 'diary']).'"', false);
    }

    public function test_each_category_shows_only_its_section(): void
    {
        // Asserted by the section's form id (a `name="locale"` marker would be polluted by the global
        // side-banner language gadget).
        $sections = [
            'diary' => 'diaryForm',
            'publicFlag' => 'publicFlagForm',
            'language' => 'languageForm',
            'general' => 'generalForm',
            'password' => 'passwordForm',
            'email' => 'member_config_email',
            'withdrawal' => 'member_config_withdrawal',
        ];
        $member = Member::factory()->create();
        Profile::factory()->preset('birthday')->create(['form_type' => 'date']); // offers the age category

        foreach ($sections as $category => $shownId) {
            $response = $this->actingAs($member)->get('/member/config?category='.$category)->assertOk();
            $response->assertSee('id="'.$shownId.'"', false);
            foreach (array_diff(array_values($sections), [$shownId]) as $hiddenId) {
                $response->assertDontSee('id="'.$hiddenId.'"', false);
            }
        }
    }

    public function test_an_unknown_category_renders_the_landing_not_404(): void
    {
        // An unknown category falls through to the landing rather than 404.
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=profile')
            ->assertOk()
            ->assertSee('Please select the item')
            ->assertDontSee('id="diaryForm"', false);

        $this->actingAs($member)->get('/member/config?category=zzz')->assertOk();
    }

    public function test_the_modern_page_renders_the_inertia_component(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('member/config')
                ->where('form.surface.value', 'modern') // preselected to the current surface (mode default)
                ->where('form.surface.options', fn ($options) => count($options) === 2) // binary: no "default" option
                ->has('form.diary.options')
                ->missing('form.age')
            );
    }

    public function test_the_access_block_category_redirects_to_the_block_list(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=accessBlock')
            ->assertRedirect(route('block.list'));
    }

    public function test_the_modern_account_detail_pages_render(): void
    {
        // The consequential account forms live one level under the settings hub (Modern only;
        // Classic keeps its ?category= pages).
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config/email')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('member/config/email')->where('email', $member->email));

        $this->actingAs($member)->get('/member/config/password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('member/config/password'));

        $this->actingAs($member)->get('/member/config/withdrawal')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('member/config/withdrawal'));
    }

    public function test_a_guest_is_redirected_from_the_account_detail_pages(): void
    {
        $this->get('/member/config/password')->assertRedirect('/login');
    }

    public function test_a_validation_failure_returns_to_the_detail_page(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->from('/member/config/password')
            ->post('/member/config/password', [
                'current_password' => 'not-the-password',
                'password' => 'new-secret-pass',
                'password_confirmation' => 'new-secret-pass',
            ])
            ->assertRedirect('/member/config/password')
            ->assertSessionHasErrors('current_password');
    }
}
