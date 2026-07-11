<?php

namespace Tests\Feature\Throttle;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Behaviour of the write limiters (App\Providers\AppServiceProvider). The limit is exercised via a
 * config() override rather than by firing the default 30 requests. An empty body is enough: the
 * throttle middleware counts the hit before the controller (and its validation) runs
 * (Illuminate\Routing\Middleware\ThrottleRequests::handleRequest checks/increments each limit, then
 * calls $next), so a 302/422/404 from validation past the limit still counts — no content factories.
 */
class WriteThrottleBehaviorTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, string}> */
    public static function limiters(): array
    {
        // limiter => [per-member config key, per-IP config key, cheapest representative route]
        return [
            'posting' => ['posting', 'posting_ip', '/diary/create'],
            'message-send' => ['message', 'message_ip', '/message/sendToFriend'],
            'friend-request' => ['friend', 'friend_ip', '/friend/link'],
            'community-join' => ['community', 'community_ip', '/community/join'],
        ];
    }

    #[DataProvider('limiters')]
    public function test_per_member_limb_throttles_and_isolates_by_member(string $memberKey, string $ipKey, string $uri): void
    {
        // Per-member limb tight (2); per-IP limb loose enough that it never trips first.
        config(["openpne.throttle.{$memberKey}" => 2, "openpne.throttle.{$ipKey}" => 1000]);

        $a = Member::factory()->create();
        $this->assertNotSame(429, $this->postAs($a, $uri)->status());
        $this->assertNotSame(429, $this->postAs($a, $uri)->status());
        $this->postAs($a, $uri)->assertStatus(429);

        // A second member has an independent per-member bucket, so their first write is not throttled.
        $b = Member::factory()->create();
        $this->assertNotSame(429, $this->postAs($b, $uri)->status());
    }

    public function test_per_ip_limb_caps_across_members_sharing_an_address(): void
    {
        // Member limb off, IP limb tight: two members on one address (127.0.0.1 in tests) share it.
        config(['openpne.throttle.posting' => 0, 'openpne.throttle.posting_ip' => 2]);

        $a = Member::factory()->create();
        $this->assertNotSame(429, $this->postAs($a, '/diary/create')->status());
        $this->assertNotSame(429, $this->postAs($a, '/diary/create')->status());

        $b = Member::factory()->create();
        $this->postAs($b, '/diary/create')->assertStatus(429);
    }

    public function test_disabling_both_limbs_lets_every_write_through(): void
    {
        // Both limbs 0 -> the limiter returns Limit::none() alone, the only shape ThrottleRequests
        // treats as unlimited (an in-array none() would degrade to a shared-key limit).
        config(['openpne.throttle.posting' => 0, 'openpne.throttle.posting_ip' => 0]);

        $member = Member::factory()->create();
        foreach (range(1, 5) as $ignored) {
            $this->assertNotSame(429, $this->postAs($member, '/diary/create')->status());
        }
    }

    /**
     * Post as a member on a clean session so AuthenticateSession never faults on a password hash
     * left by a previous member — its 302-to-login would replace the response this test asserts on
     * (ThrottleRequests runs first, so the hit still counts, but the status would lie). The
     * rate-limiter state lives in the cache store, so flushing the session does not reset the
     * counters under test.
     */
    private function postAs(Member $member, string $uri): TestResponse
    {
        $this->flushSession();

        return $this->actingAs($member)->post($uri);
    }
}
