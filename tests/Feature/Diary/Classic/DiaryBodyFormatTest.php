<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the OpenPNE 3 op_url_cmd(nl2br(...)) body rendering on diary.show: bare URLs become
 * links and HTML in the body is escaped (no stored XSS), for the diary body and comments alike.
 */
class DiaryBodyFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_diary_body_links_urls_and_escapes_html(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'body' => "Check https://example.com/page\n<script>alert(1)</script>",
        ]);

        $response = $this->actingAs($owner)->get("/diary/{$diary->getKey()}");

        $response->assertOk();
        $response->assertSee('<a href="https://example.com/page" target="_blank" rel="noopener noreferrer nofollow">https://example.com/page</a>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false); // escaped, not executed
        $response->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_comment_body_links_urls_and_escapes_html(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        DiaryComment::factory()->for($diary)->create([
            'number' => 1,
            'body' => 'see www.example.org <b>x</b>',
        ]);

        $this->actingAs($owner)->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertSee('href="http://www.example.org"', false)
            ->assertSee('>www.example.org</a>', false)
            ->assertSee('&lt;b&gt;x&lt;/b&gt;', false);
    }
}
