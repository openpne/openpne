<?php

namespace Tests\Feature\Timeline\Classic;

use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks OpenPNE 3's op_url_cmd(nl2br(...)) body rendering onto the timeline templates: bare URLs
 * become links and HTML is escaped, on both the permalink (show) and the member feed (_post).
 */
class TimelineBodyFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_body_links_urls_and_escapes_html(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create([
            'member_id' => $member->getKey(),
            'body' => "see https://example.com/page\n<script>alert(1)</script>",
        ]);

        $this->actingAs($member)->get("/timeline/{$post->getKey()}")
            ->assertOk()
            ->assertSee('<a href="https://example.com/page" target="_blank" rel="noopener noreferrer nofollow">https://example.com/page</a>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_member_feed_body_links_urls(): void
    {
        $member = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $member->getKey(), 'body' => 'go www.example.org now']);

        $this->actingAs($member)->get("/member/{$member->getKey()}/timeline")
            ->assertOk()
            ->assertSee('href="http://www.example.org"', false)
            ->assertSee('>www.example.org</a>', false);
    }
}
