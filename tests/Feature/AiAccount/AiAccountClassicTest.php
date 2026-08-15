<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Features\Group\GroupMembership;
use App\Features\Group\JoinPolicy;
use App\Models\Group;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('Open group');
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

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/delete")
            ->assertRedirect(route('member.config', ['category' => 'ai']));
        $this->assertNull(Member::find($aiAccount->getKey()));
    }
}
