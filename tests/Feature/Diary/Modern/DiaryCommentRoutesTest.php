<?php

namespace Tests\Feature\Diary\Modern;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Models\MemberImage;
use App\Support\AvatarColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiaryCommentRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_guests_are_redirected_to_login_for_comment_routes(): void
    {
        $this->post('/diary/1/comment/create')->assertRedirect('/login');
        $this->post('/diary/comment/delete/1')->assertRedirect('/login');
    }

    public function test_modern_show_includes_comments_in_props(): void
    {
        $diary = Diary::factory()->create();
        $commenter = Member::factory()->create(['name' => 'Commenter']);
        MemberImage::factory()->create(['member_id' => $commenter->getKey()]);
        $commenter->forceFill(['avatar_color' => AvatarColor::Green])->save();
        DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(), 'member_id' => $commenter->getKey(),
            'number' => 1, 'body' => 'First post',
        ]);
        $viewer = Member::factory()->create();
        $expectedImageUrl = $commenter->load('avatar.file')->avatar->file->thumbnailUrl(120, 120, square: true);

        $this->actingAs($viewer)->get("/diary/{$diary->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->component('diary/show')
                ->has('comments', 1)
                ->where('comments.0.body', 'First post')
                ->where('comments.0.number', 1)
                ->where('comments.0.author.name', 'Commenter')
                ->where('comments.0.author.imageUrl', $expectedImageUrl)
                ->where('comments.0.author.avatarColor', AvatarColor::Green->hex())
                ->where('comments.0.deletable', false)
            );
    }

    public function test_deletable_flag_is_true_for_the_comment_author(): void
    {
        $diary = Diary::factory()->create();
        $author = Member::factory()->create();
        DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(), 'member_id' => $author->getKey(),
        ]);

        $this->actingAs($author)->get("/diary/{$diary->getKey()}")
            ->assertInertia(fn ($page) => $page->where('comments.0.deletable', true));
    }

    public function test_withdrawn_author_serializes_as_null(): void
    {
        $diary = Diary::factory()->create();
        DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => null]);

        $this->actingAs(Member::factory()->create())->get("/diary/{$diary->getKey()}")
            ->assertInertia(fn ($page) => $page->where('comments.0.author', null));
    }

    public function test_modern_store_creates_comment_and_redirects_to_show(): void
    {
        $diary = Diary::factory()->create();
        $member = Member::factory()->create();

        $response = $this->actingAs($member)
            ->post("/diary/{$diary->getKey()}/comment/create", ['body' => 'Nice']);

        $response->assertRedirect("/diary/{$diary->getKey()}");
        $this->assertDatabaseHas('diary_comments', [
            'diary_id' => $diary->getKey(), 'member_id' => $member->getKey(), 'body' => 'Nice',
        ]);
    }

    public function test_modern_store_returns_404_when_diary_not_viewable(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->private()->create(['member_id' => $owner->getKey()]);
        $stranger = Member::factory()->create();

        $this->actingAs($stranger)
            ->post("/diary/{$diary->getKey()}/comment/create", ['body' => 'x'])
            ->assertNotFound();
    }

    public function test_modern_delete_returns_404_for_a_non_deletable_comment(): void
    {
        $diary = Diary::factory()->create();
        $comment = DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(), 'member_id' => Member::factory()->create()->getKey(),
        ]);

        $this->actingAs(Member::factory()->create())
            ->post("/diary/comment/delete/{$comment->getKey()}")
            ->assertNotFound();
        $this->assertDatabaseHas('diary_comments', ['id' => $comment->getKey()]);
    }

    public function test_modern_delete_removes_comment_and_redirects_to_show(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $comment = DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(), 'member_id' => Member::factory()->create()->getKey(),
        ]);

        $response = $this->actingAs($owner)->post("/diary/comment/delete/{$comment->getKey()}");

        $response->assertRedirect("/diary/{$diary->getKey()}");
        $this->assertDatabaseMissing('diary_comments', ['id' => $comment->getKey()]);
    }
}
