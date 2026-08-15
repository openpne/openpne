<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Features\Group\GroupMembership;
use App\Features\Group\JoinPolicy;
use App\Models\Group;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The Classic surface of the same feature: the AI category of member/config for the list and the
 * create form, and the account's own page for its groups and its delete button. Simpler in shape
 * than Modern (plain forms, no inline confirm) but not smaller in what it can do — an owner on
 * Classic must not need Modern to empty and delete an account.
 */
class AiAccountClassicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'classic_default']);
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
    }

    public function test_the_category_lists_what_the_member_owns_and_offers_the_create_form(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create(['name' => 'Research helper']);

        $this->actingAs($owner)->get('/member/config?category=ai')
            ->assertOk()
            ->assertSee('id="member_config_ai"', false)
            ->assertSee('id="member_config_ai_create"', false)
            ->assertSee('Research helper')
            ->assertSee('href="'.route('member.config.ai.show', ['member' => $aiAccount->getKey()]).'"', false);
    }

    public function test_the_category_is_offered_to_owners_and_to_whoever_may_create_one(): void
    {
        $member = Member::factory()->create();
        $link = 'href="'.route('member.config', ['category' => 'ai']).'"';

        $this->actingAs($member)->get('/member/config')->assertSee($link, false);

        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, false);

        // Nothing offered and nothing owned: the nav entry is gone and its URL lands on the landing.
        $this->actingAs($member)->get('/member/config')->assertDontSee($link, false);
        $this->actingAs($member)->get('/member/config?category=ai')
            ->assertOk()
            ->assertSee('Please select the item')
            ->assertDontSee('id="member_config_ai"', false);

        // An owner keeps the way in after the site stops offering creation, and the create form goes.
        $owner = Member::factory()->create();
        Member::factory()->aiAccount($owner)->create();
        $this->actingAs($owner)->get('/member/config?category=ai')
            ->assertOk()
            ->assertSee('id="member_config_ai"', false)
            ->assertSee('id="member_config_ai_disabled"', false)
            ->assertDontSee('id="member_config_ai_create"', false);
    }

    public function test_creating_from_the_category_form_lands_on_the_new_accounts_page(): void
    {
        $owner = Member::factory()->create();

        $this->actingAs($owner)->post('/member/config/ai', ['name' => 'Research helper'])
            ->assertRedirect(route('member.config.ai.show', ['member' => $owner->aiAccounts()->sole()->getKey()]));
    }

    public function test_a_refusal_lands_back_on_the_classic_category_page(): void
    {
        $this->setSnsSetting(SnsSettingKey::AiAccountLimit, 0);
        $owner = Member::factory()->create();

        $this->actingAs($owner)->post('/member/config/ai', ['name' => 'Research helper'])
            ->assertRedirect(route('member.config', ['category' => 'ai']))
            ->assertSessionHas('error');
    }

    public function test_the_account_page_renders_its_groups_and_its_delete_form(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $open = Group::factory()->create(['name' => 'Open group', 'register_policy' => JoinPolicy::Open]);

        $this->actingAs($owner)->get("/member/config/ai/{$aiAccount->getKey()}")
            ->assertOk()
            // The page opens the config sidemenu, so it needs the layout that floats it.
            ->assertSee('id="LayoutB"', false)
            ->assertSee('class="dparts pageNav"', false)
            ->assertSee('id="member_ai_account"', false)
            ->assertSee('id="member_ai_account_groups"', false)
            ->assertSee('id="member_ai_account_join"', false)
            ->assertSee('id="member_ai_account_delete"', false)
            // Classic has no confirm dialog, so the password field is both the re-auth and the step
            // that keeps a stray click from spending the account.
            ->assertSee('name="password"', false)
            ->assertSee('Open group');
    }

    public function test_the_delete_form_re_authenticates(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $page = route('member.config.ai.show', ['member' => $aiAccount->getKey()]);

        // Back to the page the form is on, where @error('password') is waiting for the message.
        $this->actingAs($owner)->from($page)
            ->post("/member/config/ai/{$aiAccount->getKey()}/delete", ['password' => 'not-the-password'])
            ->assertRedirect($page)
            ->assertSessionHasErrors('password');

        $this->assertNotNull($aiAccount->fresh());
    }

    public function test_the_account_page_carries_the_token_panel_and_mints_from_it(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $page = route('member.config.ai.show', ['member' => $aiAccount->getKey()]);

        $this->actingAs($owner)->get($page)
            ->assertOk()
            ->assertSee('id="member_ai_account_tokens"', false)
            ->assertSee('This AI account has no tokens.');

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/tokens", ['current_password' => 'password'])
            ->assertRedirect($page);

        $token = $aiAccount->tokens()->sole();
        // The credential is on the page it redirected to, beside the revoke form for that token.
        $this->actingAs($owner)->get($page)
            ->assertSee('This token is shown only this once.')
            ->assertSee(route('member.config.ai.tokens.destroy', ['member' => $aiAccount->getKey(), 'token' => $token->getKey()]));

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/tokens/{$token->getKey()}/delete")
            ->assertRedirect($page);
        $this->assertSame(0, $aiAccount->tokens()->count());
    }

    public function test_the_identity_box_carries_the_edit_forms_and_saves_them(): void
    {
        $field = Profile::factory()->preset('self_introduction')->create(['form_type' => 'textarea', 'is_edit_public_flag' => true]);
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create(['name' => 'Helper']);
        $page = route('member.config.ai.show', ['member' => $aiAccount->getKey()]);

        $this->actingAs($owner)->get($page)
            ->assertOk()
            ->assertSee('id="member_ai_account"', false)
            ->assertSee('action="'.route('member.config.ai.update', ['member' => $aiAccount->getKey()]).'"', false)
            ->assertSee('value="Helper"', false)
            ->assertSee('name="self_introduction"', false)
            // The upload is its own form, and a file needs the encoding to arrive at all.
            ->assertSee('action="'.route('member.config.ai.avatar', ['member' => $aiAccount->getKey()]).'"', false)
            ->assertSee('enctype="multipart/form-data"', false);

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", ['name' => 'Renamed', 'self_introduction' => 'Hello'])
            ->assertRedirect($page);

        $this->assertSame('Renamed', $aiAccount->fresh()->name);
        $this->assertSame('Hello', MemberProfile::query()
            ->where('member_id', $aiAccount->getKey())->where('profile_id', $field->getKey())->value('value'));
    }

    public function test_a_refused_rename_comes_back_to_the_page_with_its_error(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create(['name' => 'Helper']);
        $page = route('member.config.ai.show', ['member' => $aiAccount->getKey()]);

        $this->actingAs($owner)->from($page)->post("/member/config/ai/{$aiAccount->getKey()}", ['name' => ''])
            ->assertRedirect($page)
            ->assertSessionHasErrors('name');

        $this->assertSame('Helper', $aiAccount->fresh()->name);
    }

    public function test_the_classic_forms_upload_and_remove_the_image(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $page = route('member.config.ai.show', ['member' => $aiAccount->getKey()]);

        // Nothing uploaded yet: the remove form is not offered.
        $this->actingAs($owner)->get($page)
            ->assertDontSee('action="'.route('member.config.ai.avatar.delete', ['member' => $aiAccount->getKey()]).'"', false);

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/avatar", ['image' => UploadedFile::fake()->image('me.png', 10, 10)])
            ->assertRedirect($page);
        $this->assertSame(1, $aiAccount->fresh()->avatar()->count());

        $this->actingAs($owner)->get($page)
            ->assertSee('action="'.route('member.config.ai.avatar.delete', ['member' => $aiAccount->getKey()]).'"', false);

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/avatar/delete")->assertRedirect($page);
        $this->assertSame(0, $aiAccount->fresh()->avatar()->count());
    }

    public function test_the_account_page_is_not_someone_elses_to_open(): void
    {
        $viewer = Member::factory()->create();
        $theirs = Member::factory()->aiAccount()->create();

        $this->actingAs($viewer)->get("/member/config/ai/{$theirs->getKey()}")->assertNotFound();
    }

    public function test_the_classic_forms_join_leave_and_delete(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Open]);
        $page = route('member.config.ai.show', ['member' => $aiAccount->getKey()]);

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/groups/{$group->getKey()}/join")
            ->assertRedirect($page);
        $this->assertTrue(GroupMembership::isMember($group, $aiAccount));

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/groups/{$group->getKey()}/quit")
            ->assertRedirect($page);
        $this->assertFalse(GroupMembership::isMember($group, $aiAccount));

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/delete", ['password' => 'password'])
            ->assertRedirect(route('member.config', ['category' => 'ai']));
        $this->assertNull(Member::find($aiAccount->getKey()));
    }
}
