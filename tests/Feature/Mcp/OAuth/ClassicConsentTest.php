<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp\OAuth;

use App\Models\Member;
use App\Support\Surface;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;

/** The consent screen is a dual-surface page: the Classic form posts the same fields the Modern one does. */
class ClassicConsentTest extends OAuthTestCase
{
    private const PHONE = ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36'];

    private function openClassic(Member $viewer, array $headers = []): TestResponse
    {
        $client = $this->registerClient(name: 'Claude');
        [, $challenge] = $this->pkce();

        return $this->actingAs($viewer, 'member')->get($this->authorizeUrl($client['client_id'], $challenge), $headers)->assertOk();
    }

    private function authTokenOf(TestResponse $screen): string
    {
        preg_match('/name="auth_token" value="([^"]+)"/', (string) $screen->getContent(), $m);

        return $m[1] ?? '';
    }

    public function test_an_undecided_desktop_member_gets_the_classic_form_under_classic_default(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);
        $viewer = Member::factory()->create();
        $mine = Member::factory()->aiAccount($viewer)->create(['name' => 'Mine']);

        $screen = $this->openClassic($viewer);

        $screen->assertSee('id="oauthAuthorize"', false)
            ->assertSee('Claude')
            ->assertSee('client.example')
            ->assertSee('value="ai:'.$mine->getKey().'"', false)
            ->assertSee('value="self"', false)
            ->assertSee('name="read_only"', false)
            ->assertSee('name="current_password"', false)
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertDontSee('data-page', false);
        $this->assertNotSame('', $this->authTokenOf($screen));
    }

    public function test_classic_only_serves_the_classic_form_to_a_phone_and_over_a_modern_choice(): void
    {
        config(['openpne.surface_mode' => 'classic_only']);
        $viewer = Member::factory()->create();
        $viewer->setPreferredSurface(Surface::Modern);

        $this->openClassic($viewer, self::PHONE)->assertSee('id="oauthAuthorize"', false)->assertDontSee('data-page', false);
    }

    public function test_a_modern_choice_or_a_phone_keeps_the_modern_screen_under_classic_default(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);
        $viewer = Member::factory()->create();

        $this->openClassic($viewer, self::PHONE)
            ->assertInertia(fn (Assert $page) => $page->component('auth/oauth-authorize'));

        $viewer->setPreferredSurface(Surface::Modern);
        $this->openClassic($viewer)
            ->assertInertia(fn (Assert $page) => $page->component('auth/oauth-authorize'));
    }

    public function test_the_classic_form_approves_as_an_ai_account_and_issues_a_code(): void
    {
        config(['openpne.surface_mode' => 'classic_only']);
        $viewer = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($viewer)->create();
        $screen = $this->openClassic($viewer);

        $query = $this->redirectQuery($this->post('/oauth/authorize', [
            'auth_token' => $this->authTokenOf($screen),
            'current_password' => 'password',
            'actor' => 'ai:'.$aiAccount->getKey(),
            'read_only' => '1',
        ]));

        $this->assertArrayHasKey('code', $query);
        $this->assertSame('st4te', $query['state']);
        $this->assertSame((string) $aiAccount->getKey(), (string) Passport::authCode()->newQuery()->sole()->user_id);
    }

    public function test_a_refused_choice_returns_to_the_classic_form_with_the_error(): void
    {
        config(['openpne.surface_mode' => 'classic_only']);
        $viewer = Member::factory()->create();
        $client = $this->registerClient();
        [, $challenge] = $this->pkce();
        $url = $this->authorizeUrl($client['client_id'], $challenge);
        $screen = $this->actingAs($viewer, 'member')->get($url)->assertOk();
        $authToken = $this->authTokenOf($screen);

        $this->post('/oauth/authorize', ['auth_token' => $authToken, 'current_password' => 'password', 'actor' => 'new', 'name' => ''])
            ->assertRedirect()
            ->assertSessionHasErrors('name');

        // The same screen, the same token: the viewer corrects the choice without starting over.
        $query = $this->redirectQuery($this->post('/oauth/authorize', ['auth_token' => $authToken, 'current_password' => 'password', 'actor' => 'self']));
        $this->assertArrayHasKey('code', $query);
    }

    public function test_the_classic_form_declines(): void
    {
        config(['openpne.surface_mode' => 'classic_only']);
        $viewer = Member::factory()->create();
        $screen = $this->openClassic($viewer);

        $query = $this->redirectQuery($this->delete('/oauth/authorize', ['auth_token' => $this->authTokenOf($screen)]));

        $this->assertSame('access_denied', $query['error']);
    }
}
