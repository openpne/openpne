<?php

namespace Tests\Feature\Compat;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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

    public function test_a_retired_surface_twin_url_falls_through_to_the_catch_all(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/m/friend/list')
            ->assertStatus(308)
            ->assertRedirect('/friend/list');
    }

    /**
     * These shapes are not prefix-strippable, so each needs its explicit redirect registered ahead
     * of the catch-all.
     */
    public function test_a_reshaped_modern_get_url_redirects_to_its_canonical_shape(): void
    {
        $cases = [
            '/m/community/joined' => '/groups/mine',
            '/m/community/joined?id=7' => '/groups/mine?id=7',
            '/m/community/topic/5?order=asc&page=2' => '/topics/5?order=asc&page=2',
            '/m/community/9/members' => '/groups/9/members',
            '/m/community/9/members?page=2' => '/groups/9/members?page=2',
            '/m/community/9/pending' => '/groups/9/members/pending',
            '/m/community/9/topic' => '/groups/9/topics',
            '/m/community/9/topic/new' => '/groups/9/topics/new',
            '/m/community/topic/5' => '/topics/5',
            '/m/community/topic/5/edit' => '/topics/5/edit',
            '/m/community/9/event' => '/groups/9/events',
            '/m/community/9/event/new' => '/groups/9/events/new',
            '/m/community/event/5' => '/events/5',
            '/m/community/event/5/edit' => '/events/5/edit',
            '/m/community/event/5/members' => '/events/5/members',
        ];

        foreach ($cases as $from => $to) {
            $this->get($from)->assertStatus(308)->assertRedirect($to);
        }
    }
}
