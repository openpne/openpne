<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp\OAuth;

use App\Models\Member;
use App\Support\SnsSettingKey;
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
        return (string) ($this->formFields($screen, 'approve')['auth_token'] ?? '');
    }

    /**
     * The fields the rendered form would submit (hidden inputs, the checked radio, text inputs), so a
     * renamed field breaks the test the way it would break ApproveController. `$which` is `approve`
     * (no method override) or `deny` (the form carrying `_method`).
     *
     * @return array<string, string>
     */
    private function formFields(TestResponse $screen, string $which): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML((string) $screen->getContent());
        $xpath = new \DOMXPath($dom);

        foreach ($xpath->query('//form[@method="POST"]') as $form) {
            $hasMethod = $xpath->query('.//input[@name="_method"]', $form)->length > 0;
            if ($hasMethod !== ($which === 'deny')) {
                continue;
            }

            $fields = [];
            foreach ($xpath->query('.//input[@name]', $form) as $input) {
                $type = $input->getAttribute('type');
                if (in_array($type, ['radio', 'checkbox'], true) && ! $input->hasAttribute('checked')) {
                    continue;
                }
                if ($type === 'password') {
                    continue;
                }
                $fields[$input->getAttribute('name')] = $input->getAttribute('value');
            }
            unset($fields['_token']);

            return $fields;
        }

        $this->fail("No {$which} form rendered");
    }

    public function test_an_undecided_desktop_member_gets_the_classic_form_under_classic_default(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
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

    public function test_under_the_script_policy_the_form_may_follow_its_redirect_to_the_client(): void
    {
        config(['openpne.surface_mode' => 'classic_default', 'openpne.security.classic_script_policy' => true]);

        $policy = $this->openClassic(Member::factory()->create())->headers->all('content-security-policy')[1] ?? '';

        $this->assertStringEndsWith("; form-action 'self' ".parse_url(self::REDIRECT, PHP_URL_SCHEME).'://'.parse_url(self::REDIRECT, PHP_URL_HOST), $policy);
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

        // The rendered form, as is: the first AI account is the preselected actor.
        $fields = $this->formFields($screen, 'approve');
        $this->assertSame('ai:'.$aiAccount->getKey(), $fields['actor']);
        $query = $this->redirectQuery($this->post('/oauth/authorize', ['current_password' => 'password', 'read_only' => '1'] + $fields));

        $this->assertArrayHasKey('code', $query);
        $this->assertSame('st4te', $query['state']);
        $this->assertSame((string) $aiAccount->getKey(), (string) Passport::authCode()->newQuery()->sole()->user_id);
    }

    public function test_the_classic_form_creates_a_new_ai_account_through_its_name_field(): void
    {
        config(['openpne.surface_mode' => 'classic_only']);
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
        $viewer = Member::factory()->create();
        $screen = $this->openClassic($viewer);

        $fields = $this->formFields($screen, 'approve');
        $this->assertArrayHasKey('name', $fields);
        $query = $this->redirectQuery($this->post('/oauth/authorize', ['current_password' => 'password', 'actor' => 'new', 'name' => 'Scout'] + $fields));

        $this->assertArrayHasKey('code', $query);
        $this->assertSame('Scout', Member::query()->where('owner_member_id', $viewer->getKey())->sole()->name);
    }

    public function test_without_ai_accounts_on_offer_the_classic_form_speaks_for_the_viewer(): void
    {
        config(['openpne.surface_mode' => 'classic_only']);
        $viewer = Member::factory()->create();
        $screen = $this->openClassic($viewer);

        $fields = $this->formFields($screen, 'approve');
        $this->assertSame('self', $fields['actor']);
        $query = $this->redirectQuery($this->post('/oauth/authorize', ['current_password' => 'password'] + $fields));

        $this->assertArrayHasKey('code', $query);
        $this->assertSame((string) $viewer->getKey(), (string) Passport::authCode()->newQuery()->sole()->user_id);
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

        $fields = $this->formFields($screen, 'deny');
        $this->assertSame('DELETE', $fields['_method']);
        $query = $this->redirectQuery($this->post('/oauth/authorize', $fields));

        $this->assertSame('access_denied', $query['error']);
    }
}
