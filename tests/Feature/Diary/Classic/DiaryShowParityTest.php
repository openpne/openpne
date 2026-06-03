<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the diary.show surface elements that openpne:screen-parity marks Ported (L1): the
 * comment list, the comment post form (with its is_open notice), and the owner edit entry.
 * A regression here would silently turn a Ported claim false, so the inventory leans on this.
 * Anchors are routes/seeded data, not translated copy, so they survive wording changes.
 */
class DiaryShowParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_the_ported_comment_list(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $commenter = Member::factory()->create();
        DiaryComment::factory()->for($diary)->for($commenter, 'member')
            ->create(['number' => 1, 'body' => 'Nice entry']);

        $response = $this->actingAs($owner)->get("/diary/{$diary->getKey()}");

        $response->assertOk();
        $response->assertSee('Nice entry');                            // comment body
        $response->assertSee("/member/{$commenter->getKey()}", false); // comment author link
    }

    public function test_renders_the_ported_post_form_with_web_public_notice(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Open]);

        $response = $this->actingAs($owner)->get("/diary/{$diary->getKey()}");

        $response->assertOk();
        $response->assertSee("/diary/{$diary->getKey()}/comment/create", false); // post form action
        $response->assertSee('class="attention"', false);                        // is_open notice branch
    }

    public function test_web_public_notice_is_absent_when_the_diary_is_not_open(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);

        $this->actingAs($owner)->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertDontSee('class="attention"', false);
    }

    public function test_renders_the_visibility_label_in_the_public_hook(): void
    {
        $owner = Member::factory()->create();
        $members = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);

        $this->actingAs($owner)->get("/diary/{$members->getKey()}")
            ->assertOk()
            ->assertSee('class="public"', false) // OpenPNE 3 .public hook
            ->assertSee('All members');          // Visibility::Members->label()
    }

    public function test_visibility_label_reflects_a_web_public_diary(): void
    {
        $owner = Member::factory()->create();
        $open = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Open]);

        $this->actingAs($owner)->get("/diary/{$open->getKey()}")
            ->assertOk()
            ->assertSee('Public to Web'); // Visibility::Open->label()
    }

    public function test_renders_the_ported_owner_edit_entry(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);

        $this->actingAs($owner)->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertSee("/diary/edit/{$diary->getKey()}", false);
    }

    public function test_owner_edit_entry_is_hidden_from_other_members(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Open]);
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertDontSee("/diary/edit/{$diary->getKey()}", false);
    }
}
