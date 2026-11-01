<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureTokenMemberNotFrozen;
use App\Mcp\McpAbilities;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The `oauth` guard resolves a Member that carries only Sanctum's trait: Passport's guard asks the
 * user for nothing but withAccessToken(), which that trait answers.
 */
class OAuthGuardTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE = '/__oauth_probe';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum,oauth', 'ability:'.McpAbilities::READ])->get(self::PROBE, fn (Request $request) => [
            'member' => $request->user()->getKey(),
            'write' => $request->user()->tokenCan(McpAbilities::WRITE),
            'token' => $request->user()->currentAccessToken()::class,
        ]);
    }

    public function test_the_member_model_is_not_passports_user(): void
    {
        $this->assertNotInstanceOf(OAuthenticatable::class, new Member);
    }

    public function test_an_ai_account_is_resolved_although_the_session_provider_hides_it(): void
    {
        $aiAccount = Member::factory()->aiAccount()->create();
        Passport::actingAs($aiAccount, [McpAbilities::READ], 'oauth');

        $this->getJson(self::PROBE)
            ->assertOk()
            ->assertExactJson(['member' => $aiAccount->getKey(), 'write' => false, 'token' => AccessToken::class]);
    }

    public function test_a_frozen_owner_closes_the_guard_to_the_account(): void
    {
        $owner = Member::factory()->create(['is_login_rejected' => true]);
        $aiAccount = Member::factory()->aiAccount($owner)->create();
        Passport::actingAs($aiAccount, [McpAbilities::READ, McpAbilities::WRITE], 'oauth');

        Route::middleware(['auth:sanctum,oauth', EnsureTokenMemberNotFrozen::class])
            ->get('/__oauth_frozen', fn () => 'reached');

        $this->getJson('/__oauth_frozen')->assertUnauthorized();
    }

    public function test_a_session_alone_does_not_reach_the_guard(): void
    {
        $this->actingAs(Member::factory()->create(), 'member');

        $this->getJson(self::PROBE)->assertUnauthorized();
    }
}
