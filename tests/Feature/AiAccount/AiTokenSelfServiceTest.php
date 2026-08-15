<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Mcp\McpAbilities;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * The owner half of the token trust boundary: minting and revoking from the account's own page.
 *
 * Two contracts carry this screen. Ownership decides who may ask at all — someone else's account is
 * not there, as everywhere else in this feature — and the account password decides whether the ask
 * is honoured, so a walked-up session cannot hand out a credential that outlives it.
 */
class AiTokenSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
    }

    /** @return array{0: Member, 1: Member} owner and one AI account of theirs */
    private function ownerWithAccount(): array
    {
        $owner = Member::factory()->create();

        return [$owner, Member::factory()->aiAccount($owner)->create()];
    }

    private function tokensUrl(Member $aiAccount): string
    {
        return "/member/config/ai/{$aiAccount->getKey()}/tokens";
    }

    public function test_minting_without_the_password_is_refused(): void
    {
        [$owner, $aiAccount] = $this->ownerWithAccount();

        $this->actingAs($owner)->post($this->tokensUrl($aiAccount))
            ->assertSessionHasErrors('current_password');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_wrong_password_is_refused_on_both_token_posts(): void
    {
        [$owner, $aiAccount] = $this->ownerWithAccount();
        $token = $aiAccount->createToken(McpAbilities::TOKEN_NAME, [McpAbilities::READ])->accessToken;

        $this->actingAs($owner)->post($this->tokensUrl($aiAccount), ['current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($owner)->post($this->tokensUrl($aiAccount)."/{$token->getKey()}/delete", ['current_password' => 'wrong-password'])
            ->assertSessionHasErrors('current_password');

        $this->assertSame([$token->getKey()], PersonalAccessToken::pluck('id')->all());
    }

    public function test_the_owner_mints_a_token_and_sees_it_once(): void
    {
        [$owner, $aiAccount] = $this->ownerWithAccount();

        $this->actingAs($owner)->post($this->tokensUrl($aiAccount), ['current_password' => 'password'])
            ->assertRedirect(route('member.config.ai.show', ['member' => $aiAccount->getKey()]));

        $minted = PersonalAccessToken::sole();
        $this->assertTrue($minted->tokenable->is($aiAccount));
        $this->assertSame([McpAbilities::READ, McpAbilities::WRITE], $minted->abilities);

        // The credential is legible on the render the mint redirected to, and on no other: it is
        // flashed, not stored, and the row keeps only a hash of it.
        $plainText = null;
        $this->actingAs($owner)->get("/member/config/ai/{$aiAccount->getKey()}")
            ->assertInertia(function (AssertableInertia $page) use (&$plainText) {
                $plainText = $page->toArray()['props']['tokens']['newToken'];
                $page->has('tokens.tokens', 1)->where('tokens.tokens.0.readOnly', false);
            });
        $this->assertTrue(PersonalAccessToken::findToken((string) $plainText)?->is($minted));

        $this->actingAs($owner)->get("/member/config/ai/{$aiAccount->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tokens.newToken', null));
    }

    public function test_a_read_only_token_carries_only_the_read_ability(): void
    {
        [$owner, $aiAccount] = $this->ownerWithAccount();

        $this->actingAs($owner)->post($this->tokensUrl($aiAccount), ['current_password' => 'password', 'read_only' => '1'])
            ->assertRedirect();

        $this->assertSame([McpAbilities::READ], PersonalAccessToken::sole()->abilities);
        $this->actingAs($owner)->get("/member/config/ai/{$aiAccount->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tokens.tokens.0.readOnly', true));
    }

    public function test_the_password_is_asked_once_per_sitting(): void
    {
        [$owner, $aiAccount] = $this->ownerWithAccount();

        $this->actingAs($owner)->get("/member/config/ai/{$aiAccount->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tokens.requiresPassword', true));

        $this->actingAs($owner)->post($this->tokensUrl($aiAccount), ['current_password' => 'password']);

        // Inside the window the field is neither shown nor demanded — one re-auth per sitting, not
        // one per token.
        $this->actingAs($owner)->get("/member/config/ai/{$aiAccount->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tokens.requiresPassword', false));
        $this->actingAs($owner)->post($this->tokensUrl($aiAccount))->assertSessionHasNoErrors();

        $this->assertSame(2, PersonalAccessToken::count());
    }

    public function test_the_owner_revokes_one_token_and_leaves_the_others(): void
    {
        [$owner, $aiAccount] = $this->ownerWithAccount();
        $doomed = $aiAccount->createToken(McpAbilities::TOKEN_NAME, [McpAbilities::READ])->accessToken;
        $survivor = $aiAccount->createToken(McpAbilities::TOKEN_NAME, [McpAbilities::READ])->accessToken;

        $this->actingAs($owner)
            ->post($this->tokensUrl($aiAccount)."/{$doomed->getKey()}/delete", ['current_password' => 'password'])
            ->assertRedirect(route('member.config.ai.show', ['member' => $aiAccount->getKey()]));

        $this->assertSame([$survivor->getKey()], PersonalAccessToken::pluck('id')->all());
    }

    public function test_a_token_that_is_not_this_accounts_is_not_there(): void
    {
        [$owner, $aiAccount] = $this->ownerWithAccount();
        [, $theirAccount] = $this->ownerWithAccount();
        $theirs = $theirAccount->createToken(McpAbilities::TOKEN_NAME, [McpAbilities::READ])->accessToken;
        $otherPurpose = $aiAccount->createToken('other', ['reporting'])->accessToken;

        foreach ([$theirs->getKey(), $otherPurpose->getKey(), 999999] as $id) {
            $this->actingAs($owner)
                ->post($this->tokensUrl($aiAccount)."/{$id}/delete", ['current_password' => 'password'])
                ->assertNotFound();
        }

        $this->assertSame(2, PersonalAccessToken::count());
    }

    public function test_someone_elses_ai_account_has_no_tokens_to_manage(): void
    {
        $viewer = Member::factory()->create();
        [, $theirs] = $this->ownerWithAccount();
        $token = $theirs->createToken(McpAbilities::TOKEN_NAME, [McpAbilities::READ])->accessToken;

        $this->actingAs($viewer)->post($this->tokensUrl($theirs), ['current_password' => 'password'])
            ->assertNotFound();
        $this->actingAs($viewer)->post($this->tokensUrl($theirs)."/{$token->getKey()}/delete", ['current_password' => 'password'])
            ->assertNotFound();

        // A human member's own account is not mintable from this screen either: the operator CLI is
        // the only way to a person's token.
        $this->actingAs($viewer)->post("/member/config/ai/{$viewer->getKey()}/tokens", ['current_password' => 'password'])
            ->assertNotFound();

        $this->assertSame([$token->getKey()], PersonalAccessToken::pluck('id')->all());
    }

    public function test_an_ai_account_of_a_frozen_owner_is_refused_rather_than_minted_for(): void
    {
        // Barely reachable — a ban ends the owner's sessions — so this is the belt: the mint asks
        // the freeze question again, on the locked rows, whatever the session says.
        [$owner, $aiAccount] = $this->ownerWithAccount();
        $this->actingAs($owner);
        $owner->forceFill(['is_login_rejected' => true])->save();

        $this->post($this->tokensUrl($aiAccount), ['current_password' => 'password'])
            ->assertSessionHas('error', __('This AI account cannot be given a token right now.'));

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_token_minted_here_works_at_the_endpoint_until_it_is_revoked(): void
    {
        $this->setSnsSetting(SnsSettingKey::FeatureMcpEnabled, true);
        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, true);
        [$owner, $aiAccount] = $this->ownerWithAccount();
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $aiAccount->getKey()]);

        $this->actingAs($owner)->post($this->tokensUrl($aiAccount), ['current_password' => 'password']);
        $plainText = (string) session('ai_account.new_token');
        $token = PersonalAccessToken::sole();

        $call = fn (): TestResponse => $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'post-talk-message', 'arguments' => ['group_id' => $group->getKey(), 'body' => 'from the client']],
        ], ['Authorization' => 'Bearer '.$plainText]);

        $this->freshRequestState();
        $call()->assertOk();
        $this->assertDatabaseHas('group_messages', [
            'group_id' => $group->getKey(),
            'member_id' => $aiAccount->getKey(),
            'body' => 'from the client',
        ]);

        // Named guard: the endpoint's own middleware left `sanctum` as the default one, and an
        // unqualified actingAs would hand the owner to that guard rather than to the session.
        $this->freshRequestState();
        $this->actingAs($owner, 'member')
            ->post($this->tokensUrl($aiAccount)."/{$token->getKey()}/delete", ['current_password' => 'password'])
            ->assertRedirect(route('member.config.ai.show', ['member' => $aiAccount->getKey()]));

        // The client's credential stops being one the moment the owner says so.
        $this->freshRequestState();
        $call()->assertUnauthorized();
        $this->assertSame(1, $group->messages()->count());
    }

    public function test_the_panel_says_when_the_endpoint_is_switched_off(): void
    {
        [$owner, $aiAccount] = $this->ownerWithAccount();
        $this->setSnsSetting(SnsSettingKey::FeatureMcpEnabled, false);

        // The unit is the endpoint's kill switch, not this screen's: a token is still mintable and,
        // more to the point, still revocable while it is off.
        $this->actingAs($owner)->get("/member/config/ai/{$aiAccount->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tokens.mcpEnabled', false));
        $this->actingAs($owner)->post($this->tokensUrl($aiAccount), ['current_password' => 'password'])
            ->assertRedirect();

        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_the_token_posts_draw_on_the_same_budget_as_the_other_management_posts(): void
    {
        foreach (['member.config.ai.tokens.store', 'member.config.ai.tokens.destroy'] as $name) {
            $this->assertContains(
                'throttle:ai-manage',
                Route::getRoutes()->getByName($name)->gatherMiddleware(),
                "route [{$name}] lost throttle:ai-manage",
            );
        }

        [$owner, $aiAccount] = $this->ownerWithAccount();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($owner)->post($this->tokensUrl($aiAccount), ['current_password' => 'password'])->assertRedirect();
        }

        $this->actingAs($owner)->post($this->tokensUrl($aiAccount))->assertStatus(429);
        $this->assertSame(5, PersonalAccessToken::count());
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        [, $aiAccount] = $this->ownerWithAccount();

        $this->post($this->tokensUrl($aiAccount))->assertRedirect(route('login'));
    }
}
