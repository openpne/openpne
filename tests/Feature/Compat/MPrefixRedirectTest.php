<?php

namespace Tests\Feature\Compat;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The retired /m/ URL space permanently redirects to canonical URLs via the compat.m_prefix
 * catch-all: 308 so a stale in-flight form keeps its method, query string preserved. Registered
 * last, so a still-live /m/ route (the transition-era surface twins) always wins over it.
 */
class MPrefixRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_retired_m_url_redirects_to_the_canonical_url_with_308(): void
    {
        $this->get('/m/notifications')
            ->assertStatus(308)
            ->assertRedirect('/notifications');
    }

    public function test_the_query_string_rides_along(): void
    {
        $this->get('/m/community/recent?page=2')
            ->assertStatus(308)
            ->assertRedirect('/community/recent?page=2');
    }

    public function test_a_post_keeps_its_method_via_308(): void
    {
        // 308 (not 301) so the client repeats the POST against the canonical URL.
        $this->post('/m/notifications/read-all')
            ->assertStatus(308)
            ->assertRedirect('/notifications/read-all');
    }

    public function test_the_bare_m_prefix_redirects_to_the_root(): void
    {
        $this->get('/m')
            ->assertStatus(308)
            ->assertRedirect('/');
    }

    public function test_a_live_m_route_is_not_shadowed_by_the_catch_all(): void
    {
        // The surface twins still registered under /m/ must keep winning (registration order)
        // until they are removed.
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/m/friend/list')->assertOk();
    }
}
