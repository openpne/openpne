<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureTokenMemberNotFrozen;
use App\Http\Middleware\ThrottleMcpByIp;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

/**
 * The endpoint itself: the third realm, reached by bearer token alone and holding no session. The
 * tools it exposes are exercised in TalkToolsTest; what is pinned here is the gate every one of
 * them sits behind — including on the two methods that only ever answer 405, which the package
 * registers beside the POST and which a middleware chained onto Mcp::web()'s return value misses.
 */
class McpEndpointTest extends McpTestCase
{
    /** @param  array<string, mixed>  $params */
    private function rpc(string $token, string $method, array $params = []): TestResponse
    {
        return $this->postJson('/mcp', $this->envelope($method, $params), $this->bearer($token));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function envelope(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_a_bearer_token_reaches_the_tool_list(): void
    {
        $member = Member::factory()->create();

        $this->rpc($this->token($member), 'tools/list')
            ->assertOk()
            ->assertSee('list-talk-rooms')
            ->assertSee('post-talk-message');
    }

    public function test_a_tool_call_writes_through_the_endpoint(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);

        $this->rpc($this->token($member), 'tools/call', [
            'name' => 'post-talk-message',
            'arguments' => ['group_id' => $group->getKey(), 'body' => 'over the wire'],
        ])->assertOk();

        $this->assertDatabaseHas('group_messages', [
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'body' => 'over the wire',
        ]);
    }

    public function test_a_request_with_no_token_is_answered_401_rather_than_sent_to_a_login_form(): void
    {
        $this->postJson('/mcp', $this->envelope('tools/list'))
            ->assertUnauthorized()
            // The transport spec's challenge, which the package would otherwise attach inside the
            // gate — where a 401 thrown by it never arrives.
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');

        // Also for a request that does not ask for JSON: the guest redirect is skipped for this path
        // (bootstrap/app.php), so a browser pointed at the endpoint gets the same 401 a client does.
        $this->freshRequestState();
        $this->post('/mcp', $this->envelope('tools/list'))->assertUnauthorized();
    }

    public function test_a_signed_in_member_session_alone_does_not_reach_the_endpoint(): void
    {
        $this->actingAs(Member::factory()->create(), 'member');

        $this->postJson('/mcp', $this->envelope('tools/list'))->assertUnauthorized();
    }

    public function test_a_bearer_request_starts_no_session_and_sets_no_cookie(): void
    {
        // The suite runs on the array driver, which would keep a started session out of the DB
        // whatever the endpoint did; the database driver is what makes the absence observable.
        config(['session.driver' => 'database']);

        $response = $this->rpc($this->token(Member::factory()->create()), 'tools/list')->assertOk();

        $this->assertSame(0, DB::table(config('session.member_table'))->count());
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_every_method_on_the_path_is_gated_the_same_way(): void
    {
        $token = $this->token(Member::factory()->create());

        // A guard memoizes its user and the container outlives a request here, so each call below
        // is preceded by the fresh-worker reset — without it the second request inherits the first
        // one's answer to "who is this".
        foreach ([['GET', []], ['DELETE', []], ['POST', $this->envelope('tools/list')]] as [$method, $body]) {
            $this->freshRequestState();
            // The gate is on the group, not on the POST that Mcp::web() returns.
            $this->json($method, '/mcp', $body)->assertUnauthorized();
        }

        // With a token the two spec-mandated stubs answer 405 — the package's own answer, and so
        // proof the request reached it.
        foreach (['GET', 'DELETE'] as $method) {
            $this->freshRequestState();
            $this->json($method, '/mcp', [], $this->bearer($token))->assertStatus(405);
        }
    }

    public function test_a_token_without_the_read_ability_is_refused(): void
    {
        $token = Member::factory()->create()->createToken('mcp', ['reporting'])->plainTextToken;

        $this->rpc($token, 'tools/list')->assertForbidden();
    }

    public function test_a_wildcard_token_passes_the_ability_gate(): void
    {
        // Sanctum's own semantics: `*` answers every `can`. The first-party way to mint a token is
        // `openpne:mcp:token`, which always issues named abilities — so this is a contract about
        // what a hand-minted wildcard does, not a hole in the gate. See docs/internals/mcp.md.
        $token = Member::factory()->create()->createToken('mcp', ['*'])->plainTextToken;

        $this->rpc($token, 'tools/list')->assertOk();
    }

    public function test_switching_the_unit_off_closes_the_endpoint_to_a_valid_token(): void
    {
        $token = $this->token(Member::factory()->create());
        $this->setSnsSetting(Feature::Mcp->settingKey(), false);

        $this->rpc($token, 'tools/list')->assertNotFound();
        $this->getJson('/mcp', $this->bearer($token))->assertNotFound();
        $this->json('DELETE', '/mcp', [], $this->bearer($token))->assertNotFound();
    }

    public function test_a_frozen_members_token_is_refused_even_if_the_row_survived(): void
    {
        $member = Member::factory()->create();
        $token = $this->token($member);

        // A ban deletes the tokens in the same transaction as the flag (RejectMemberLogin), so the
        // flag is set directly here: what is under test is the belt behind that sweep.
        $member->forceFill(['is_login_rejected' => true])->save();

        $this->rpc($token, 'tools/list')->assertUnauthorized();
    }

    public function test_the_per_ip_cap_applies_before_a_credential_is_seen(): void
    {
        config(['openpne.throttle.mcp_ip' => 1]);

        $this->postJson('/mcp', $this->envelope('tools/list'))->assertUnauthorized();
        // The framework's own throttle sits below Authenticate in the priority list, so only a
        // limiter outside that list can answer an unauthenticated caller at all.
        $this->postJson('/mcp', $this->envelope('tools/list'))->assertStatus(429);
    }

    public function test_the_per_token_cap_applies_once_a_credential_is_accepted(): void
    {
        config(['openpne.throttle.mcp' => 1, 'openpne.throttle.mcp_ip' => 100]);
        $token = $this->token(Member::factory()->create());

        $this->rpc($token, 'tools/list')->assertOk();
        $this->rpc($token, 'tools/list')->assertStatus(429);
    }

    public function test_the_gate_runs_in_the_declared_order_on_every_method(): void
    {
        $expected = [
            ThrottleMcpByIp::class,
            Authenticate::class,
            EnsureTokenMemberNotFrozen::class,
            CheckForAnyAbility::class,
            EnsureFeatureEnabled::class,
            ThrottleRequests::class,
        ];

        $router = app(Router::class);
        $seen = 0;

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() !== 'mcp') {
                continue;
            }
            $seen++;

            $resolved = array_values(array_filter(
                array_map(fn (mixed $m): string => is_string($m) ? explode(':', $m)[0] : '', $router->gatherRouteMiddleware($route)),
                fn (string $m): bool => in_array($m, $expected, true),
            ));

            // The order is not the one the group declares by itself: the framework's priority list
            // re-sorts Authenticate, the feature gate and ThrottleRequests among themselves, and
            // this is the arrangement that comes out — brute force bounded before authentication,
            // the toggle read after it so its state never leaks, the token's own cap last.
            $this->assertSame($expected, $resolved, "{$route->methods()[0]} /mcp");
        }

        $this->assertSame(3, $seen, 'Mcp::web should register a GET, a DELETE and a POST');
    }
}
