<?php

namespace Tests\Feature\Community\Classic;

use App\Models\Community;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The community description is free text, so it runs through the shared op_url_cmd(nl2br(...))
 * rendering (x-user-text): bare URLs become links and HTML is escaped.
 */
class CommunityDescriptionFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_description_links_urls_and_escapes_html(): void
    {
        $community = Community::factory()->create([
            'description' => "home https://example.com/x\n<b>x</b>",
        ]);

        $this->actingAs(Member::factory()->create())->get(route('community.show', $community))
            ->assertOk()
            ->assertSee('<a href="https://example.com/x" target="_blank" rel="noopener noreferrer nofollow">https://example.com/x</a>', false)
            ->assertSee('&lt;b&gt;x&lt;/b&gt;', false);
    }
}
