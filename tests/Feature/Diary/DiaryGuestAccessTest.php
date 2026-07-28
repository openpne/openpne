<?php

namespace Tests\Feature\Diary;

use App\Files\FileStorage;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The signed-out half of the diary module (OpenPNE 3 diary/config/security.yml `is_secure: false`):
 * what a guest may read, what bounces them to login, and that switching web-public diaries off
 * closes all of it again.
 */
class DiaryGuestAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'classic_default']);
    }

    private function diary(Member $author, Visibility $visibility, string $title = 'Entry'): Diary
    {
        return Diary::factory()->create([
            'member_id' => $author->getKey(),
            'title' => $title,
            'visibility' => $visibility,
        ]);
    }

    // show ----------------------------------------------------------------------

    public function test_a_guest_reads_a_web_public_entry_in_the_pre_login_shell(): void
    {
        $diary = $this->diary(Member::factory()->create(), Visibility::Open, 'Open entry');

        $response = $this->get("/diary/{$diary->getKey()}");

        $response->assertOk();
        $response->assertSee('id="page_diary_show"', false);
        $response->assertSee('Open entry');
        // OpenPNE 3 only flipped the action to is_secure for an authenticated viewer.
        $response->assertSee('class="insecure_page"', false);
    }

    public function test_a_guest_sees_the_comment_thread_but_no_post_form(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author, Visibility::Open);
        DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(),
            'member_id' => $author->getKey(),
            'number' => 1,
            'body' => 'A visible comment',
        ]);

        $response = $this->get("/diary/{$diary->getKey()}");

        $response->assertOk();
        $response->assertSee('A visible comment');
        $response->assertDontSee('id="formDiaryComment"', false);
        $response->assertDontSee(route('diary.comment.store', $diary), false);
    }

    public function test_a_guest_is_sent_to_login_for_a_non_web_public_entry(): void
    {
        $diary = $this->diary(Member::factory()->create(), Visibility::Members, 'Members entry');

        $this->get("/diary/{$diary->getKey()}")->assertRedirect(route('login'));
    }

    public function test_a_guest_gets_the_same_answer_for_an_entry_that_does_not_exist(): void
    {
        // OpenPNE 3 forwarded both to login, so the response is no existence oracle.
        $this->get('/diary/999999')->assertRedirect(route('login'));
    }

    public function test_neighbour_links_stay_inside_the_web_public_tier(): void
    {
        $author = Member::factory()->create();
        $older = $this->diary($author, Visibility::Open, 'Older open');
        $this->diary($author, Visibility::Members, 'Hidden middle');
        $newer = $this->diary($author, Visibility::Open, 'Newer open');

        $this->get("/diary/{$older->getKey()}")
            ->assertOk()
            ->assertSee(route('diary.show', $newer), false)
            ->assertDontSee('Hidden middle');
    }

    // feed / search -------------------------------------------------------------

    public function test_the_feed_and_search_list_only_web_public_entries(): void
    {
        $this->diary(Member::factory()->create(), Visibility::Open, 'Open note');
        $this->diary(Member::factory()->create(), Visibility::Members, 'Members note');

        $this->get('/diary/list')->assertOk()->assertSee('Open note')->assertDontSee('Members note');
        $this->get('/diary/search?keyword=note')->assertOk()->assertSee('Open note')->assertDontSee('Members note');
    }

    public function test_the_diary_top_url_redirects_to_the_feed_for_a_guest(): void
    {
        $this->get('/diary')->assertRedirect(route('diary.list'));
    }

    // listMember ----------------------------------------------------------------

    public function test_a_guest_reaches_the_archive_of_an_author_who_publishes(): void
    {
        $author = Member::factory()->create();
        $this->diary($author, Visibility::Open, 'Open entry');
        $this->diary($author, Visibility::Private, 'Private entry');

        $this->get("/diary/listMember/{$author->getKey()}")
            ->assertOk()
            ->assertSee('Open entry')
            ->assertDontSee('Private entry');
    }

    public function test_a_guest_is_sent_to_login_for_an_author_who_publishes_nothing(): void
    {
        $author = Member::factory()->create();
        $this->diary($author, Visibility::Members);

        $this->get("/diary/listMember/{$author->getKey()}")->assertRedirect(route('login'));
    }

    public function test_a_guest_is_sent_to_login_for_the_id_less_archive(): void
    {
        // /diary/listMember with no id is "my archive": it needs a viewer to be about anyone.
        $this->get('/diary/listMember')->assertRedirect(route('login'));
    }

    public function test_an_empty_month_still_renders_for_an_author_who_publishes(): void
    {
        // Eligibility is the author's, not the window's (OpenPNE 3 hasOpenDiary): narrowing to a
        // month with nothing in it must not read as "this author is private".
        $author = Member::factory()->create();
        $this->diary($author, Visibility::Open, 'Open entry')->forceFill(['created_at' => '2026-03-10 09:00:00'])->save();

        $this->get("/diary/listMember/{$author->getKey()}/2026/4")
            ->assertOk()
            // The empty-state box, not a bounce.
            ->assertSee('id="diaryList"', false);
    }

    public function test_the_archive_of_an_unknown_member_answers_as_a_private_one_does(): void
    {
        $this->get('/diary/listMember/999999')->assertRedirect(route('login'));
        $this->get('/diary/listMember/999999/2026/3')->assertRedirect(route('login'));
    }

    // the web-public switch -----------------------------------------------------

    public function test_switching_web_public_off_closes_every_guest_diary_route(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author, Visibility::Open);
        config(['openpne.diary.allow_web_public' => false]);

        foreach ([
            '/diary',
            '/diary/list',
            '/diary/search?keyword=entry',
            "/diary/listMember/{$author->getKey()}",
            "/diary/listMember/{$author->getKey()}/2026/3",
            "/diary/{$diary->getKey()}",
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_switching_web_public_off_stops_the_bytes_of_a_known_image_url(): void
    {
        // The controller gate alone would leave an already-published image URL working.
        $author = Member::factory()->create();
        $diary = $this->diary($author, Visibility::Open);
        $file = $this->diaryImage($diary);

        $this->get($file->url())->assertOk();

        config(['openpne.diary.allow_web_public' => false]);
        $this->get($file->url())->assertNotFound();
    }

    public function test_a_member_still_reads_a_web_public_entry_after_the_switch_goes_off(): void
    {
        // The switch governs the web-public audience, never the membership's own access.
        $diary = $this->diary(Member::factory()->create(), Visibility::Open, 'Open entry');
        config(['openpne.diary.allow_web_public' => false]);

        $this->actingAs(Member::factory()->create())
            ->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertSee('Open entry');
    }

    /** An attached image with real bytes, owned by the diary (the morph FilePolicy resolves). */
    private function diaryImage(Diary $diary): File
    {
        $file = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'diary',
            'related_entity_id' => $diary->getKey(),
            'byte_size' => strlen('PNGDATA'),
        ]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $file->getKey(), 'number' => 1]);

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, 'PNGDATA');
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return $file;
    }
}
