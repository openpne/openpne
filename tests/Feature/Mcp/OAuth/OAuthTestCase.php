<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp\OAuth;

use App\Models\Member;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Mcp\McpTestCase;

abstract class OAuthTestCase extends McpTestCase
{
    protected const REDIRECT = 'https://client.example/callback';

    protected function setUp(): void
    {
        parent::setUp();

        // Registration is capped per IP; the flows here register once per test and the cap has a test of its own.
        config(['openpne.throttle.oauth_register_ip' => 0]);
    }

    /** @return array<string, mixed> the registration response */
    protected function registerClient(array $redirects = [self::REDIRECT], string $name = 'Claude'): array
    {
        return $this->postJson('/oauth/register', ['client_name' => $name, 'redirect_uris' => $redirects])
            ->assertCreated()
            ->json();
    }

    /** @return array{0: string, 1: string} verifier and S256 challenge */
    protected function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [$verifier, $challenge];
    }

    protected function authorizeUrl(string $clientId, string $challenge, string $scope = 'mcp:use', string $redirect = self::REDIRECT): string
    {
        return '/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'scope' => $scope,
            'state' => 'st4te',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Opens the consent screen as the viewer and answers it with the given form. */
    protected function consent(Member $viewer, string $url, array $form): TestResponse
    {
        $screen = $this->actingAs($viewer, 'member')->get($url);
        $screen->assertOk();
        $authToken = $screen->inertiaPage()['props']['authToken'];

        return $this->post('/oauth/authorize', ['auth_token' => $authToken] + $form);
    }

    /** @return array<string, string> the query the client was redirected with */
    protected function redirectQuery(TestResponse $response): array
    {
        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    protected function exchange(string $clientId, string $code, string $verifier, string $redirect = self::REDIRECT): TestResponse
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'redirect_uri' => $redirect,
            'code_verifier' => $verifier,
        ]);
    }

    protected function refresh(string $clientId, string $refreshToken): TestResponse
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * The whole flow for one viewer and one choice.
     *
     * @return array{access_token: string, refresh_token: string, client_id: string}
     */
    protected function connect(Member $viewer, array $form, string $scope = 'mcp:use'): array
    {
        $client = $this->registerClient();
        [$verifier, $challenge] = $this->pkce();
        $query = $this->redirectQuery($this->consent($viewer, $this->authorizeUrl($client['client_id'], $challenge, $scope), $form));
        $this->freshRequestState();
        $tokens = $this->exchange($client['client_id'], $query['code'], $verifier)->assertOk()->json();

        return $tokens + ['client_id' => $client['client_id']];
    }

    protected function rpc(string $bearer, string $method, array $params = []): TestResponse
    {
        $this->freshRequestState();

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], [
            'Authorization' => 'Bearer '.$bearer,
        ]);
    }

    protected function callTool(string $bearer, string $name, array $arguments = []): TestResponse
    {
        return $this->rpc($bearer, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
    }
}
