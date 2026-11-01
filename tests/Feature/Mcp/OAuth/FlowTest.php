<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp\OAuth;

use App\Features\AiAccount\Actions\BindConsentActor;
use App\Features\Member\Actions\RejectMemberLogin;
use App\Mcp\McpAbilities;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Laravel\Passport\Passport;

class FlowTest extends OAuthTestCase
{
    private const MISSING_WRITE = 'This token may only read';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
    }

    public function test_registration_takes_https_or_a_loopback_address_and_nothing_else(): void
    {
        $this->registerClient(['https://claude.ai/api/mcp/auth_callback']);
        $this->registerClient(['http://localhost:3118/callback']);
        $this->registerClient(['http://127.0.0.1:65000/cb']);

        foreach (['http://client.example/cb', 'https://client.example/cb#frag', 'http://127.0.0.1/cb#frag', 'claude://cb', "https://client.example/c\tb"] as $uri) {
            $this->postJson('/oauth/register', ['redirect_uris' => [$uri]])
                ->assertStatus(400)
                ->assertJsonPath('error', 'invalid_redirect_uri');
        }
    }

    public function test_a_linked_prompt_login_does_not_end_the_session(): void
    {
        $viewer = Member::factory()->create();
        $client = $this->registerClient();
        [, $challenge] = $this->pkce();

        $this->actingAs($viewer, 'member')->get($this->authorizeUrl($client['client_id'], $challenge).'&prompt=login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/oauth-authorize'));
        $this->assertAuthenticatedAs($viewer, 'member');
    }

    public function test_a_guest_meets_the_login_screen_before_consent(): void
    {
        $client = $this->registerClient();
        [, $challenge] = $this->pkce();

        $this->get($this->authorizeUrl($client['client_id'], $challenge))->assertRedirect(route('login'));
    }

    public function test_the_consent_screen_names_the_host_and_offers_the_viewers_ai_accounts(): void
    {
        $viewer = Member::factory()->create();
        $mine = Member::factory()->aiAccount($viewer)->create(['name' => 'Mine']);
        Member::factory()->aiAccount()->create(['name' => 'Theirs']);
        $client = $this->registerClient(name: 'Claude');
        [, $challenge] = $this->pkce();

        $this->actingAs($viewer, 'member')->get($this->authorizeUrl($client['client_id'], $challenge))
            ->assertInertia(fn ($page) => $page
                ->component('auth/oauth-authorize')
                ->where('client.name', 'Claude')
                ->where('client.host', 'client.example')
                ->where('actors.canCreate', true)
                ->has('actors.aiAccounts', 1)
                ->where('actors.aiAccounts.0.id', $mine->getKey())
                ->has('authToken')
                ->has('csrfToken'));
    }

    public function test_connecting_as_an_existing_ai_account_speaks_for_it(): void
    {
        $viewer = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($viewer)->create();

        $tokens = $this->connect($viewer, ['actor' => 'ai:'.$aiAccount->getKey()]);

        $this->rpc($tokens['access_token'], 'tools/list')->assertOk()->assertSee('list-talk-rooms');
        $row = Passport::token()->newQuery()->where('user_id', $aiAccount->getKey())->sole();
        $this->assertEqualsCanonicalizing(['mcp:use', McpAbilities::READ, McpAbilities::WRITE], $row->scopes);
        // Past the ability gate: the refusal is the room's, not the token's.
        $this->callTool($tokens['access_token'], 'mark-talk-read', ['group_id' => 999999, 'message_id' => 1])
            ->assertOk()->assertDontSee(self::MISSING_WRITE);
    }

    public function test_connecting_as_a_new_ai_account_creates_it_first(): void
    {
        $viewer = Member::factory()->create();

        $tokens = $this->connect($viewer, ['actor' => 'new', 'name' => 'Scout']);

        $created = Member::query()->where('owner_member_id', $viewer->getKey())->sole();
        $this->assertSame('Scout', $created->name);
        $this->assertSame((string) $created->getKey(), (string) Passport::token()->newQuery()->sole()->user_id);
        $this->rpc($tokens['access_token'], 'tools/list')->assertOk();
    }

    public function test_connecting_as_yourself_speaks_for_the_viewer(): void
    {
        $viewer = Member::factory()->create();

        $tokens = $this->connect($viewer, ['actor' => 'self']);

        $this->assertSame((string) $viewer->getKey(), (string) Passport::token()->newQuery()->sole()->user_id);
        $this->rpc($tokens['access_token'], 'tools/list')->assertOk();
    }

    public function test_read_only_drops_the_write_scope_whatever_the_client_asked_for(): void
    {
        $viewer = Member::factory()->create();

        $tokens = $this->connect($viewer, ['actor' => 'self', 'read_only' => '1'], scope: 'mcp:use mcp:write');

        $this->assertEqualsCanonicalizing(['mcp:use', McpAbilities::READ], Passport::token()->newQuery()->sole()->scopes);
        $this->callTool($tokens['access_token'], 'mark-talk-read', ['group_id' => 1, 'message_id' => 1])
            ->assertOk()->assertSee(self::MISSING_WRITE);
    }

    public function test_someone_elses_ai_account_cannot_be_chosen(): void
    {
        $viewer = Member::factory()->create();
        $theirs = Member::factory()->aiAccount()->create();
        $client = $this->registerClient();
        [, $challenge] = $this->pkce();

        $this->consent($viewer, $this->authorizeUrl($client['client_id'], $challenge), ['actor' => 'ai:'.$theirs->getKey()])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Passport::authCode()->newQuery()->count());
    }

    public function test_a_refused_creation_can_be_corrected_on_the_same_screen(): void
    {
        $this->setSnsSetting(SnsSettingKey::AiAccountLimit, 0);
        $viewer = Member::factory()->create();
        $client = $this->registerClient();
        [, $challenge] = $this->pkce();
        $url = $this->authorizeUrl($client['client_id'], $challenge);

        $screen = $this->actingAs($viewer, 'member')->get($url);
        $authToken = $screen->inertiaPage()['props']['authToken'];

        $this->post('/oauth/authorize', ['auth_token' => $authToken, 'current_password' => 'password', 'actor' => 'new', 'name' => 'Scout'])
            ->assertRedirect()
            ->assertSessionHas('error');

        // The same screen, the same token: the viewer corrects the choice without starting over.
        $query = $this->redirectQuery($this->post('/oauth/authorize', ['auth_token' => $authToken, 'current_password' => 'password', 'actor' => 'self']));
        $this->assertArrayHasKey('code', $query);
        $this->assertSame('st4te', $query['state']);
    }

    public function test_a_consent_that_passed_the_door_before_a_password_change_persists_nothing(): void
    {
        $viewer = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($viewer)->create();
        $client = $this->registerClient();
        [, $challenge] = $this->pkce();
        $screen = $this->actingAs($viewer, 'member')->get($this->authorizeUrl($client['client_id'], $challenge));
        $authToken = $screen->inertiaPage()['props']['authToken'];

        // The password changes after auth.session let the request in and before the rows are locked.
        $this->app->bind(BindConsentActor::class, fn () => new class extends BindConsentActor
        {
            public function __invoke(Member $viewer, string|int $actor): Member
            {
                Member::whereKey($viewer->getKey())->update(['password' => bcrypt('changed-elsewhere')]);

                return parent::__invoke($viewer, $actor);
            }
        });

        $this->post('/oauth/authorize', ['auth_token' => $authToken, 'current_password' => 'password', 'actor' => 'ai:'.$aiAccount->getKey()])
            ->assertRedirect(route('login'));

        $this->assertSame(0, Passport::authCode()->newQuery()->count());
    }

    public function test_approving_asks_for_the_password_once_per_sitting(): void
    {
        $viewer = Member::factory()->create();
        $client = $this->registerClient();
        [, $challenge] = $this->pkce();
        $url = $this->authorizeUrl($client['client_id'], $challenge);

        $screen = $this->actingAs($viewer, 'member')->get($url);
        $screen->assertInertia(fn ($page) => $page->where('requiresPassword', true));
        $authToken = $screen->inertiaPage()['props']['authToken'];

        $this->post('/oauth/authorize', ['auth_token' => $authToken, 'actor' => 'self'])
            ->assertSessionHasErrors('current_password');
        $this->post('/oauth/authorize', ['auth_token' => $authToken, 'current_password' => 'wrong', 'actor' => 'self'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame(0, Passport::authCode()->newQuery()->count());

        $this->redirectQuery($this->post('/oauth/authorize', ['auth_token' => $authToken, 'current_password' => 'password', 'actor' => 'self']));

        // Inside the window the next connection asks no password and the field is not offered.
        $second = $this->actingAs($viewer, 'member')->get($url);
        $second->assertInertia(fn ($page) => $page->where('requiresPassword', false));
        $query = $this->redirectQuery($this->post('/oauth/authorize', ['auth_token' => $second->inertiaPage()['props']['authToken'], 'actor' => 'self']));
        $this->assertArrayHasKey('code', $query);
    }

    public function test_a_stale_auth_token_is_refused(): void
    {
        $viewer = Member::factory()->create();
        $client = $this->registerClient();
        [, $challenge] = $this->pkce();

        $this->actingAs($viewer, 'member')->get($this->authorizeUrl($client['client_id'], $challenge))->assertOk();

        $this->post('/oauth/authorize', ['auth_token' => 'not-it', 'current_password' => 'password', 'actor' => 'self'])->assertForbidden();
    }

    public function test_declining_sends_the_client_access_denied(): void
    {
        $viewer = Member::factory()->create();
        $client = $this->registerClient();
        [, $challenge] = $this->pkce();
        $screen = $this->actingAs($viewer, 'member')->get($this->authorizeUrl($client['client_id'], $challenge));

        $query = $this->redirectQuery($this->delete('/oauth/authorize', ['auth_token' => $screen->inertiaPage()['props']['authToken']]));

        $this->assertSame('access_denied', $query['error']);
        $this->assertSame('st4te', $query['state']);
    }

    public function test_reconnecting_asks_again(): void
    {
        $viewer = Member::factory()->create();
        $tokens = $this->connect($viewer, ['actor' => 'self']);
        [, $challenge] = $this->pkce();

        $this->freshRequestState();
        $this->actingAs($viewer, 'member')->get($this->authorizeUrl($tokens['client_id'], $challenge))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/oauth-authorize'));
    }

    public function test_a_refresh_rotates_the_pair_and_the_old_one_is_spent(): void
    {
        $viewer = Member::factory()->create();
        $tokens = $this->connect($viewer, ['actor' => 'self']);

        $this->freshRequestState();
        $renewed = $this->refresh($tokens['client_id'], $tokens['refresh_token'])->assertOk()->json();

        $this->assertNotSame($tokens['access_token'], $renewed['access_token']);
        $this->rpc($renewed['access_token'], 'tools/list')->assertOk();
        $this->rpc($tokens['access_token'], 'tools/list')->assertUnauthorized();

        $this->freshRequestState();
        $this->refresh($tokens['client_id'], $tokens['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(1, Passport::token()->newQuery()->where('revoked', false)->count());
    }

    public function test_registration_is_capped_per_address_before_anything_is_stored(): void
    {
        config(['openpne.throttle.oauth_register_ip' => 1]);

        $this->registerClient();
        $this->postJson('/oauth/register', ['redirect_uris' => [self::REDIRECT]])->assertStatus(429);
        $this->assertSame(1, Passport::client()->newQuery()->count());
    }

    public function test_a_code_approved_before_a_ban_is_swept_with_the_ban(): void
    {
        Member::factory()->create();
        $viewer = Member::factory()->create();
        $client = $this->registerClient();
        [$verifier, $challenge] = $this->pkce();
        $query = $this->redirectQuery($this->consent($viewer, $this->authorizeUrl($client['client_id'], $challenge), ['actor' => 'self']));

        app(RejectMemberLogin::class)($viewer);

        $this->freshRequestState();
        $this->exchange($client['client_id'], $query['code'], $verifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(0, Passport::token()->newQuery()->count());
    }

    public function test_a_code_the_sweep_did_not_reach_is_still_refused_for_a_frozen_member(): void
    {
        $viewer = Member::factory()->create();
        $client = $this->registerClient();
        [$verifier, $challenge] = $this->pkce();
        $query = $this->redirectQuery($this->consent($viewer, $this->authorizeUrl($client['client_id'], $challenge), ['actor' => 'self']));

        // The flag alone, as a row restored from a backup would carry it.
        $viewer->forceFill(['is_login_rejected' => true])->save();

        $this->freshRequestState();
        $this->exchange($client['client_id'], $query['code'], $verifier)->assertUnauthorized()->assertJsonPath('error', 'access_denied');
        $this->assertSame(0, Passport::token()->newQuery()->count());
    }

    public function test_the_token_realm_endpoints_start_no_session_and_set_no_cookie(): void
    {
        $viewer = Member::factory()->create();
        $client = $this->registerClient();
        [$verifier, $challenge] = $this->pkce();
        $query = $this->redirectQuery($this->consent($viewer, $this->authorizeUrl($client['client_id'], $challenge), ['actor' => 'self']));
        config(['session.driver' => 'database']);

        $this->freshRequestState();
        $exchange = $this->exchange($client['client_id'], $query['code'], $verifier)->assertOk();
        $this->freshRequestState();
        $register = $this->postJson('/oauth/register', ['redirect_uris' => [self::REDIRECT]])->assertCreated();

        $this->assertSame([], $exchange->headers->getCookies());
        $this->assertSame([], $register->headers->getCookies());
        $this->assertDatabaseCount(config('session.table'), 0);
    }

    public function test_a_second_submit_of_the_same_screen_is_a_stale_token(): void
    {
        $viewer = Member::factory()->create();
        $client = $this->registerClient();
        [, $challenge] = $this->pkce();
        $screen = $this->actingAs($viewer, 'member')->get($this->authorizeUrl($client['client_id'], $challenge));
        $form = ['auth_token' => $screen->inertiaPage()['props']['authToken'], 'current_password' => 'password', 'actor' => 'new', 'name' => 'Scout'];

        $this->redirectQuery($this->post('/oauth/authorize', $form));
        $this->post('/oauth/authorize', $form)->assertForbidden();

        $this->assertSame(1, Member::query()->where('owner_member_id', $viewer->getKey())->count());
        $this->assertSame(1, Passport::authCode()->newQuery()->count());
    }

    public function test_a_refused_bearer_points_the_client_at_the_resource_metadata(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');

        $this->getJson('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()
            ->assertJsonPath('resource', url('/mcp'))
            ->assertJsonPath('authorization_servers.0', url('/'));

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
            ->assertJsonPath('token_endpoint', url('/oauth/token'))
            ->assertJsonPath('registration_endpoint', url('/oauth/register'))
            ->assertJsonPath('code_challenge_methods_supported.0', 'S256');
    }

    public function test_switching_the_unit_off_closes_the_whole_surface(): void
    {
        $this->setSnsSetting(SnsSettingKey::FeatureMcpEnabled, false);

        // The 401 is answered ahead of the gate and still names the metadata, which then answers 404.
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertNotFound();
        $this->getJson('/.well-known/oauth-authorization-server')->assertNotFound();
        $this->postJson('/oauth/register', ['redirect_uris' => [self::REDIRECT]])->assertNotFound();
        $this->post('/oauth/token', ['grant_type' => 'refresh_token'])->assertNotFound();
        $this->actingAs(Member::factory()->create(), 'member')->get('/oauth/authorize')->assertNotFound();
    }
}
