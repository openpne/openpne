<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryCommentImage;
use App\Models\Member;
use App\Support\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiaryCommentRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_for_comment_routes(): void
    {
        $this->post('/diary/1/comment/create')->assertRedirect('/login');
        $this->get('/diary/comment/deleteConfirm/1')->assertRedirect('/login');
        $this->post('/diary/comment/delete/1')->assertRedirect('/login');
    }

    // create --------------------------------------------------------------------

    public function test_store_creates_comment_and_redirects_to_the_diary(): void
    {
        $diary = Diary::factory()->create();
        $member = Member::factory()->create();

        $response = $this->actingAs($member)
            ->post("/diary/{$diary->getKey()}/comment/create", ['body' => 'Hello']);

        $response->assertRedirect("/diary/{$diary->getKey()}");
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('diary_comments', [
            'diary_id' => $diary->getKey(), 'member_id' => $member->getKey(),
            'number' => 1, 'body' => 'Hello',
        ]);
    }

    public function test_store_requires_a_non_blank_body(): void
    {
        $diary = Diary::factory()->create();
        $member = Member::factory()->create();

        // OpenPNE 3 right-trims first, so whitespace-only is rejected as empty.
        $this->actingAs($member)
            ->post("/diary/{$diary->getKey()}/comment/create", ['body' => "   \n"])
            ->assertSessionHasErrors('body');
        $this->assertDatabaseCount('diary_comments', 0);
    }

    public function test_store_returns_404_when_the_diary_is_not_viewable(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->private()->create(['member_id' => $owner->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->post("/diary/{$diary->getKey()}/comment/create", ['body' => 'sneaky'])
            ->assertNotFound();
        $this->assertDatabaseCount('diary_comments', 0);
    }

    // show ----------------------------------------------------------------------

    public function test_diary_show_renders_comments(): void
    {
        $diary = Diary::factory()->create();
        $commenter = Member::factory()->create(['name' => 'Commenter']);
        DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(), 'member_id' => $commenter->getKey(),
            'number' => 1, 'body' => 'First post',
        ]);
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertSee('id="diary_comment_list"', false)
            ->assertSee('First post')
            ->assertSee('Commenter')
            ->assertSee('id="formDiaryComment"', false); // OpenPNE 3 op_include_form id
    }

    /**
     * diaryComment/_list.php: the timestamp stacks year / date / time in the dt (XDateTimeJaBr),
     * and the photos sit inside div.body ABOVE the text.
     */
    public function test_a_comment_row_stacks_its_timestamp_and_keeps_photos_above_the_text(): void
    {
        $viewer = Member::factory()->create();
        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);
        $comment = $diary->comments()->create([
            'member_id' => $viewer->getKey(), 'body' => 'with picture', 'number' => 1,
        ]);
        // created_at is not fillable; pin it so the stacked dt is assertable byte-exact.
        $comment->forceFill(['created_at' => CarbonImmutable::create(2026, 6, 24, 21, 5)])->save();
        DiaryCommentImage::factory()->create(['diary_comment_id' => $comment->getKey()]);

        // ja locale: the stacked pattern is OpenPNE 3's ja_JP culture; other locales stay one line.
        $content = (string) $this->actingAs($viewer)->withSession(['locale' => 'ja'])
            ->get(route('diary.show', $diary))->assertOk()->getContent();

        // Stacked dt: year / date / time joined by <br />.
        $this->assertStringContainsString('<dt>2026年<br />06月24日<br />21:05</dt>', $content);
        // div.body opens, then ul.photo, then the text, and only then does the div close —
        // the OpenPNE 3 order, with the close position pinning "inside" for real.
        $photoPos = strpos($content, '<ul class="photo">');
        $textPos = strpos($content, 'with picture');
        $this->assertNotFalse($photoPos);
        $this->assertNotFalse($textPos);
        $openPos = strrpos(substr($content, 0, $photoPos), '<div class="body">');
        $this->assertNotFalse($openPos, 'ul.photo must be preceded by a div.body open');
        // The FIRST close after the open (nothing inside the body nests a div): if it precedes the
        // text, the photos and the text are not sharing one div.body.
        $closePos = strpos($content, '</div>', $openPos);
        $this->assertNotFalse($closePos);
        $this->assertLessThan($photoPos, $openPos);
        $this->assertLessThan($textPos, $photoPos, 'photos render above the comment text');
        $this->assertLessThan($closePos, $textPos, 'the body div must not close before the text — photos and text share it');
    }

    public function test_delete_link_shows_only_to_the_comment_or_diary_author(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $commenter = Member::factory()->create();
        $comment = DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(), 'member_id' => $commenter->getKey(),
        ]);
        $confirmUrl = "/diary/comment/deleteConfirm/{$comment->getKey()}";

        $this->actingAs($commenter)->get("/diary/{$diary->getKey()}")->assertSee($confirmUrl, false);
        $this->actingAs($owner)->get("/diary/{$diary->getKey()}")->assertSee($confirmUrl, false);

        $stranger = Member::factory()->create();
        $this->actingAs($stranger)->get("/diary/{$diary->getKey()}")->assertDontSee($confirmUrl, false);
    }

    public function test_withdrawn_author_is_labelled_and_only_diary_author_may_delete(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $comment = DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(), 'member_id' => null,
        ]);
        $confirmUrl = "/diary/comment/deleteConfirm/{$comment->getKey()}";

        $this->actingAs($owner)->get("/diary/{$diary->getKey()}")
            ->assertSee('Withdrawn member')
            ->assertSee($confirmUrl, false);

        $stranger = Member::factory()->create();
        $this->actingAs($stranger)->get("/diary/{$diary->getKey()}")->assertDontSee($confirmUrl, false);
    }

    // delete --------------------------------------------------------------------

    public function test_delete_confirm_renders_with_op3_body_id(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $comment = DiaryComment::factory()->create(['diary_id' => $diary->getKey()]);

        $this->actingAs($owner)->get("/diary/comment/deleteConfirm/{$comment->getKey()}")
            ->assertOk()
            // The action lives in the diaryComment module, not diary.
            ->assertSee('id="page_diaryComment_deleteConfirm"', false);
    }

    public function test_delete_confirm_returns_404_for_a_non_deletable_comment(): void
    {
        $diary = Diary::factory()->create();
        $comment = DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(), 'member_id' => Member::factory()->create()->getKey(),
        ]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->get("/diary/comment/deleteConfirm/{$comment->getKey()}")
            ->assertNotFound();
    }

    public function test_delete_removes_comment_and_redirects_to_the_diary(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $comment = DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(), 'member_id' => Member::factory()->create()->getKey(),
        ]);

        $response = $this->actingAs($owner)->post("/diary/comment/delete/{$comment->getKey()}");

        $response->assertRedirect("/diary/{$diary->getKey()}");
        $response->assertSessionHas('status');
        $this->assertDatabaseMissing('diary_comments', ['id' => $comment->getKey()]);
    }

    public function test_delete_returns_404_for_a_non_deletable_comment(): void
    {
        $diary = Diary::factory()->create();
        $comment = DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(), 'member_id' => Member::factory()->create()->getKey(),
        ]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->post("/diary/comment/delete/{$comment->getKey()}")
            ->assertNotFound();
        $this->assertDatabaseHas('diary_comments', ['id' => $comment->getKey()]);
    }
}
