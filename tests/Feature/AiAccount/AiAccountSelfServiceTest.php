<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Every test that switches AiAccountsEnabled off also asserts the management paths still answer.
 */
class AiAccountSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
    }

    public function test_the_list_shows_what_the_member_owns_and_how_much_of_the_allowance_is_left(): void
    {
        $this->setSnsSetting(SnsSettingKey::AiAccountLimit, 3);
        $owner = Member::factory()->create();
        $mine = Member::factory()->aiAccount($owner)->create(['name' => 'Research helper']);
        // Someone else's, to prove the list is scoped by ownership and not by "is an AI account".
        Member::factory()->aiAccount()->create();

        $this->actingAs($owner)->get('/member/config/ai')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/config/ai/index')
                ->where('used', 1)
                ->where('limit', 3)
                ->where('canCreate', true)
                ->has('accounts', 1)
                ->where('accounts.0.id', $mine->getKey())
                ->where('accounts.0.isAi', true));
    }

    public function test_a_member_creates_an_account_and_lands_on_its_page(): void
    {
        $owner = Member::factory()->create();

        $response = $this->actingAs($owner)->post('/member/config/ai', ['name' => 'Research helper']);

        $created = $owner->aiAccounts()->sole();
        $this->assertSame('Research helper', $created->name);
        $response->assertRedirect(route('member.config.ai.show', ['member' => $created->getKey()]));
    }

    public function test_a_nameless_account_is_refused_by_the_form(): void
    {
        $owner = Member::factory()->create();

        $this->actingAs($owner)->post('/member/config/ai', ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, $owner->aiAccounts()->count());
    }

    public function test_the_limit_refuses_one_more_and_says_so(): void
    {
        $this->setSnsSetting(SnsSettingKey::AiAccountLimit, 1);
        $owner = Member::factory()->create();
        Member::factory()->aiAccount($owner)->create();

        $this->actingAs($owner)->post('/member/config/ai', ['name' => 'Second'])
            ->assertRedirect(route('member.config.ai'))
            ->assertSessionHas('error', __('You already have as many AI accounts as this site allows.'));

        $this->assertSame(1, $owner->aiAccounts()->count());
        // The form is not offered either, so the refusal is the backstop, not the only signal.
        $this->actingAs($owner)->get('/member/config/ai')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', false)->where('enabled', true));
    }

    public function test_someone_elses_account_is_not_there_at_all(): void
    {
        $viewer = Member::factory()->create();
        $theirs = Member::factory()->aiAccount()->create();

        $this->actingAs($viewer)->get("/member/config/ai/{$theirs->getKey()}")->assertNotFound();
        $this->actingAs($viewer)->post("/member/config/ai/{$theirs->getKey()}/delete")->assertNotFound();
        // The identity POSTs answer the same way, and ahead of their own validation: a rename that
        // came back with a field error would report that the account is there.
        $this->actingAs($viewer)->post("/member/config/ai/{$theirs->getKey()}", ['name' => 'Renamed'])
            ->assertNotFound()->assertSessionHasNoErrors();
        $this->actingAs($viewer)->post("/member/config/ai/{$theirs->getKey()}/avatar")
            ->assertNotFound()->assertSessionHasNoErrors();
        $this->actingAs($viewer)->post("/member/config/ai/{$theirs->getKey()}/avatar/delete")->assertNotFound();

        $this->assertTrue($theirs->exists());
        $this->assertNotNull($theirs->fresh());
        $this->assertSame($theirs->name, $theirs->fresh()->name);
    }

    public function test_the_delete_password_never_answers_for_an_account_that_is_not_the_viewers(): void
    {
        // Otherwise a "wrong password" against a stranger's id, versus a 404 against an unused
        // one, would say which member ids are AI accounts.
        $viewer = Member::factory()->create();
        $theirs = Member::factory()->aiAccount()->create();
        $unused = (int) Member::max('id') + 1000;

        // Four POSTs, inside the ai-manage budget of five: a 429 would hide what is being asserted.
        foreach (['password', 'not-the-password'] as $password) {
            $this->actingAs($viewer)->post("/member/config/ai/{$theirs->getKey()}/delete", ['password' => $password])
                ->assertNotFound()
                ->assertSessionHasNoErrors();
            $this->actingAs($viewer)->post("/member/config/ai/{$unused}/delete", ['password' => $password])
                ->assertNotFound()
                ->assertSessionHasNoErrors();
        }

        $this->assertNotNull($theirs->fresh());
    }

    public function test_a_human_member_is_not_reachable_as_an_ai_account(): void
    {
        $viewer = Member::factory()->create();
        $other = Member::factory()->create();

        $this->actingAs($viewer)->get("/member/config/ai/{$other->getKey()}")->assertNotFound();
        $this->actingAs($viewer)->get("/member/config/ai/{$viewer->getKey()}")->assertNotFound();
        $this->actingAs($viewer)->post("/member/config/ai/{$other->getKey()}/delete", ['password' => 'password'])
            ->assertNotFound();
    }

    public function test_the_owner_reaches_their_own_account_page(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();

        $this->actingAs($owner)->get("/member/config/ai/{$aiAccount->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/config/ai/show')
                ->where('account.id', $aiAccount->getKey())
                ->where('account.isAi', true)
                ->has('groups'));
    }

    public function test_the_owner_deletes_their_account(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/delete", ['password' => 'password'])
            ->assertRedirect(route('member.config.ai'));

        $this->assertNull(Member::find($aiAccount->getKey()));
    }

    public function test_deleting_re_authenticates_with_the_current_password(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $url = "/member/config/ai/{$aiAccount->getKey()}/delete";

        $this->actingAs($owner)->post($url)->assertSessionHasErrors('password');
        $this->actingAs($owner)->post($url, ['password' => 'not-the-password'])->assertSessionHasErrors('password');
        $this->assertNotNull($aiAccount->fresh());

        $this->actingAs($owner)->post($url, ['password' => 'password'])->assertRedirect(route('member.config.ai'));
        $this->assertNull(Member::find($aiAccount->getKey()));
    }

    public function test_switching_the_setting_off_stops_creation_and_nothing_else(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, false);

        $this->actingAs($owner)->post('/member/config/ai', ['name' => 'Another'])
            ->assertRedirect(route('member.config.ai'))
            ->assertSessionHas('error', __('This site is not offering AI accounts right now.'));
        $this->assertSame(1, $owner->aiAccounts()->count());

        $this->actingAs($owner)->get('/member/config/ai')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('enabled', false)->where('canCreate', false)->has('accounts', 1));
        $this->actingAs($owner)->get("/member/config/ai/{$aiAccount->getKey()}")->assertOk();
        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/delete", ['password' => 'password'])
            ->assertRedirect();
        $this->assertNull(Member::find($aiAccount->getKey()));
    }

    public function test_the_settings_hub_offers_the_section_to_owners_and_to_whoever_may_create_one(): void
    {
        $withNone = Member::factory()->create();

        $this->actingAs($withNone)->get('/member/config')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('form.ai'));

        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, false);

        $this->actingAs($withNone)->get('/member/config')
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('form.ai'));

        $owner = Member::factory()->create();
        Member::factory()->aiAccount($owner)->create();
        $this->actingAs($owner)->get('/member/config')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('form.ai.count', 1));
    }

    public function test_creating_and_deleting_are_throttled_but_the_renders_are_not(): void
    {
        // The GETs are left out, so a refresh cannot spend the budget.
        $names = [
            'member.config.ai.store',
            'member.config.ai.destroy',
            'member.config.ai.update',
            'member.config.ai.avatar',
            'member.config.ai.avatar.delete',
        ];

        foreach ($names as $name) {
            $this->assertContains(
                'throttle:ai-manage',
                Route::getRoutes()->getByName($name)->gatherMiddleware(),
                "route [{$name}] lost throttle:ai-manage",
            );
        }

        foreach (['member.config.ai', 'member.config.ai.show'] as $name) {
            $this->assertNotContains(
                'throttle:ai-manage',
                Route::getRoutes()->getByName($name)->gatherMiddleware(),
                "route [{$name}] must not be throttled",
            );
        }
    }

    public function test_every_route_naming_an_account_carries_the_ownership_gate(): void
    {
        // The gate has to be route middleware: left to the controller, a FormRequest would answer
        // before the ownership check — on any sibling route that grows one later, too.
        $names = [
            'member.config.ai.show',
            'member.config.ai.update',
            'member.config.ai.avatar',
            'member.config.ai.avatar.delete',
            'member.config.ai.destroy',
            'member.config.ai.groups.join',
            'member.config.ai.groups.quit',
            'member.config.ai.groups.cancel',
        ];

        foreach ($names as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertStringContainsString('{member}', $route->uri(), "route [{$name}] no longer names an account");
            $this->assertContains('can:manageAiAccount,member', $route->gatherMiddleware(),
                "route [{$name}] lost the ownership gate");
        }
    }

    public function test_the_creation_budget_runs_out(): void
    {
        $this->setSnsSetting(SnsSettingKey::AiAccountLimit, 100);
        $owner = Member::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($owner)->post('/member/config/ai', ['name' => "Helper {$i}"])->assertRedirect();
        }

        $this->actingAs($owner)->post('/member/config/ai', ['name' => 'One too many'])->assertStatus(429);
        $this->assertSame(5, $owner->aiAccounts()->count());
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $aiAccount = Member::factory()->aiAccount()->create();

        $this->get('/member/config/ai')->assertRedirect(route('login'));
        $this->get("/member/config/ai/{$aiAccount->getKey()}")->assertRedirect(route('login'));
    }
}
