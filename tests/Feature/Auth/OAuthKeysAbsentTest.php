<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Route;
use Tests\Support\PassportTestKeys;
use Tests\TestCase;

/** A site that never ran passport:keys is the site before this feature existed. */
class OAuthKeysAbsentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PassportTestKeys::absent();
        $this->refreshApplication();
    }

    protected function tearDown(): void
    {
        PassportTestKeys::ensure();

        parent::tearDown();
    }

    public function test_the_oauth_surface_is_not_registered_and_the_bearer_refusal_is_the_old_one(): void
    {
        $this->assertFalse(Route::has('passport.token'));
        $this->assertFalse(Route::has('passport.authorizations.authorize'));
        $this->assertFalse(Route::has('mcp.oauth.protected-resource.nested'));

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
    }
}
