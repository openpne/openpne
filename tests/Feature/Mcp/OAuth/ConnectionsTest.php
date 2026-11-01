<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp\OAuth;

use App\Mcp\OAuth\ConsentReauth;
use App\Models\Member;
use App\Support\PruneOAuth;
use App\Support\SnsSettingKey;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

class ConnectionsTest extends OAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
    }

    /** A live grant row pair, without the flow. */
    private function grant(Member $subject, array $scopes = ['mcp:use', 'mcp:read', 'mcp:write'], ?Client $client = null, bool $accessExpired = false, bool $refreshLive = true): Token
    {
        $client ??= Client::factory()->asPublic()->create(['name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']]);
        $access = Passport::token()->forceFill([
            'id' => Str::random(80), 'user_id' => $subject->getKey(), 'client_id' => $client->getKey(),
            'scopes' => $scopes, 'revoked' => false, 'expires_at' => $accessExpired ? now()->subHour() : now()->addHour(),
        ]);
        $access->save();
        Passport::refreshToken()->forceFill([
            'id' => Str::random(80), 'access_token_id' => $access->getKey(), 'revoked' => ! $refreshLive, 'expires_at' => now()->addDay(),
        ])->save();

        return $access;
    }

    public function test_the_settings_hub_lists_the_members_own_and_their_accounts_connections(): void
    {
        $viewer = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($viewer)->create(['name' => 'Scout']);
        $mine = $this->grant($viewer, ['mcp:use', 'mcp:read']);
        $mine->forceFill(['created_at' => now()->subMinute()])->save();
        $theirs = $this->grant($aiAccount);
        $this->grant(Member::factory()->aiAccount()->create());
        $this->grant($viewer, accessExpired: true, refreshLive: false);

        $this->actingAs($viewer, 'member')->get('/member/config')
            ->assertInertia(fn ($page) => $page
                ->has('form.connections.items', 2)
                ->where('form.connections.requiresPassword', true)
                ->where('form.connections.items.0.id', $theirs->getKey())
                ->where('form.connections.items.0.actor.name', 'Scout')
                ->where('form.connections.items.0.host', 'claude.ai')
                ->where('form.connections.items.0.readOnly', false)
                ->where('form.connections.items.1.id', $mine->getKey())
                ->where('form.connections.items.1.actor', null)
                ->where('form.connections.items.1.readOnly', true));
    }

    public function test_the_hub_offers_no_section_while_nothing_is_connected(): void
    {
        $this->actingAs(Member::factory()->create(), 'member')->get('/member/config')
            ->assertInertia(fn ($page) => $page->missing('form.connections'));
    }

    public function test_an_expired_access_token_with_a_live_refresh_token_is_still_a_connection(): void
    {
        $viewer = Member::factory()->create();
        $this->grant($viewer, accessExpired: true);

        $this->actingAs($viewer, 'member')->get('/member/config')
            ->assertInertia(fn ($page) => $page->has('form.connections.items', 1));
    }

    public function test_ending_a_connection_takes_the_password_and_both_rows(): void
    {
        $viewer = Member::factory()->create();
        $access = $this->grant($viewer);

        $this->actingAs($viewer, 'member')->post("/member/config/connections/{$access->getKey()}/delete")
            ->assertSessionHasErrors('current_password');
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $access->getKey()]);

        $this->post("/member/config/connections/{$access->getKey()}/delete", ['current_password' => 'password'])
            ->assertRedirect(route('member.config'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $access->getKey()]);
        $this->assertDatabaseMissing('oauth_refresh_tokens', ['access_token_id' => $access->getKey()]);
        $this->assertTrue(ConsentReauth::isFresh($this->app->make('session.store')));
    }

    public function test_someone_elses_connection_is_not_there_at_all(): void
    {
        $viewer = Member::factory()->create();
        $stranger = Member::factory()->create();
        $access = $this->grant($stranger);

        $this->actingAs($viewer, 'member')->post("/member/config/connections/{$access->getKey()}/delete", ['current_password' => 'password'])
            ->assertNotFound();
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $access->getKey()]);
    }

    public function test_a_connection_can_be_ended_while_the_endpoint_is_switched_off(): void
    {
        $this->setSnsSetting(SnsSettingKey::FeatureMcpEnabled, false);
        $viewer = Member::factory()->create();
        $access = $this->grant($viewer);

        $this->actingAs($viewer, 'member')->post("/member/config/connections/{$access->getKey()}/delete", ['current_password' => 'password'])
            ->assertRedirect(route('member.config'));
        $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $access->getKey()]);
    }

    public function test_the_ai_account_page_counts_its_connections(): void
    {
        $viewer = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($viewer)->create();
        $this->grant($aiAccount);
        $this->grant($aiAccount, accessExpired: true, refreshLive: false);

        $this->actingAs($viewer, 'member')->get("/member/config/ai/{$aiAccount->getKey()}")
            ->assertInertia(fn ($page) => $page->where('tokens.connections', 1));
    }

    public function test_pruning_keeps_what_the_flow_still_needs(): void
    {
        $viewer = Member::factory()->create();
        $live = $this->grant($viewer);
        $expiredButRefreshable = $this->grant($viewer, accessExpired: true);
        $spent = $this->grant($viewer, accessExpired: true, refreshLive: false);
        Passport::refreshToken()->newQuery()->where('access_token_id', $spent->getKey())->update(['expires_at' => now()->subDays(8)]);
        $spent->forceFill(['expires_at' => now()->subDays(8)])->save();
        $justExpired = $this->grant($viewer, accessExpired: true, refreshLive: false);
        $usedClient = $live->client;
        $freshClient = Client::factory()->asPublic()->create();
        $forgottenClient = Client::factory()->asPublic()->create(['created_at' => now()->subDays(8)]);
        $clientWithCode = Client::factory()->asPublic()->create(['created_at' => now()->subDays(8)]);
        Passport::authCode()->forceFill([
            'id' => Str::random(80), 'user_id' => $viewer->getKey(), 'client_id' => $clientWithCode->getKey(),
            'scopes' => json_encode(['mcp:use']), 'revoked' => false, 'expires_at' => now()->addMinutes(5),
        ])->save();

        PruneOAuth::run();

        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $live->getKey()]);
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $expiredButRefreshable->getKey()]);
        $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $spent->getKey()]);
        // Expired an hour ago: kept inside the grace window a refresh may still be in flight for.
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $justExpired->getKey()]);
        $this->assertDatabaseHas('oauth_clients', ['id' => $usedClient->getKey()]);
        $this->assertDatabaseHas('oauth_clients', ['id' => $freshClient->getKey()]);
        $this->assertDatabaseHas('oauth_clients', ['id' => $clientWithCode->getKey()]);
        $this->assertDatabaseMissing('oauth_clients', ['id' => $forgottenClient->getKey()]);
    }
}
