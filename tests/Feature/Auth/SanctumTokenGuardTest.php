<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Mcp\McpAbilities;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The `sanctum` guard as this app configures it: a bearer token is the only credential it accepts
 * (config/sanctum.php pins `guard` to an empty list, so there is no first-party session fallback).
 */
class SanctumTokenGuardTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE = '/__sanctum_probe';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:sanctum')->get(self::PROBE, fn (Request $request) => [
            'member' => $request->user()->getKey(),
            'write' => $request->user()->tokenCan(McpAbilities::WRITE),
        ]);
    }

    public function test_a_bearer_token_authenticates_its_member_and_carries_its_abilities(): void
    {
        $member = Member::factory()->create();
        $token = $member->createToken(McpAbilities::TOKEN_NAME, [McpAbilities::READ]);

        $this->getJson(self::PROBE, ['Authorization' => 'Bearer '.$token->plainTextToken])
            ->assertOk()
            ->assertExactJson(['member' => $member->getKey(), 'write' => false]);
    }

    public function test_a_request_without_a_token_is_rejected(): void
    {
        $this->getJson(self::PROBE)->assertUnauthorized();

        // A browser-shaped request meets the app-wide guest redirect instead of a 401; asserted so the
        // difference is a recorded fact rather than a surprise.
        $this->get(self::PROBE)->assertRedirect(route('login'));
    }

    public function test_a_logged_in_member_session_does_not_authenticate_the_token_guard(): void
    {
        $member = Member::factory()->create();
        $this->actingAs($member, 'member');

        $this->getJson(self::PROBE)->assertUnauthorized();

        // The empty guard list is what does it, not some incidental gap: name a first-party guard
        // and the identical request authenticates — and does so with every ability, because a
        // session user is given a TransientToken that answers `can` with true.
        config(['sanctum.guard' => ['member']]);

        $this->getJson(self::PROBE)->assertOk()->assertExactJson([
            'member' => $member->getKey(),
            'write' => true,
        ]);
    }

    public function test_a_deleted_token_stops_authenticating(): void
    {
        $member = Member::factory()->create();
        $token = $member->createToken(McpAbilities::TOKEN_NAME, [McpAbilities::READ]);
        $member->tokens()->delete();

        $this->getJson(self::PROBE, ['Authorization' => 'Bearer '.$token->plainTextToken])
            ->assertUnauthorized();
    }

    public function test_the_spa_csrf_cookie_route_is_not_registered(): void
    {
        // Token-only: config/sanctum.php sets `routes => false`, so the cookie endpoint that exists
        // solely for the SPA session flow is never mounted.
        $this->assertNull(Route::getRoutes()->getByName('sanctum.csrf-cookie'));
    }
}
