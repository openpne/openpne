<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryImage;
use App\Models\Member;
use App\Support\BodyFormat;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the diary.show elements openpne:screen-parity marks Ported (L1), which the inventory leans
 * on. Anchors are routes and seeded data, not translated copy, so they survive wording changes.
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
            ->assertSee('class="public"', false) // the .public skin hook
            ->assertSee('All members');          // Visibility::Members->label()
    }

    public function test_visibility_label_reflects_a_web_public_diary(): void
    {
        $owner = Member::factory()->create();
        $open = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Open]);

        $this->actingAs($owner)->get("/diary/{$open->getKey()}")
            ->assertOk()
            ->assertSee('Anyone on the web'); // Visibility::Open->label()
    }

    public function test_renders_the_link_to_the_authors_diary_list(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);

        $this->actingAs($owner)->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertSee('id="lineLinkToDiaryMemberList"', false) // the .line skin hook
            ->assertSee("/diary/listMember/{$owner->getKey()}", false);
    }

    public function test_renders_prev_next_links_to_the_authors_adjacent_diaries(): void
    {
        $owner = Member::factory()->create();
        $older = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $current = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $newer = Diary::factory()->create(['member_id' => $owner->getKey()]);

        $this->actingAs($owner)->get("/diary/{$current->getKey()}")
            ->assertOk()
            ->assertSee('class="block prevNextLinkLine"', false) // markup hook
            ->assertSee('<p class="prev"><a href="'.route('diary.show', $older).'"', false)
            ->assertSee('<p class="next"><a href="'.route('diary.show', $newer).'"', false);
    }

    public function test_omits_the_prev_next_block_for_a_lone_diary(): void
    {
        $owner = Member::factory()->create();
        $only = Diary::factory()->create(['member_id' => $owner->getKey()]);

        $this->actingAs($owner)->get("/diary/{$only->getKey()}")
            ->assertOk()
            ->assertDontSee('prevNextLinkLine', false);
    }

    public function test_renders_the_entry_as_a_dl_headed_by_its_author(): void
    {
        $owner = Member::factory()->create(['name' => 'Alice']);
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'title' => 'Rainy day']);

        $html = $this->actingAs($owner)->get("/diary/{$diary->getKey()}")->assertOk()->getContent();

        // OpenPNE 3 names the author in the heading ("Diary of %1%") and carries the entry title
        // into the dd, so the dt column holds nothing but the timestamp.
        $this->assertStringContainsString('<h3>Diary of Alice</h3>', $html);
        $this->assertMatchesRegularExpression(
            '~<dl>\s*<dt>[^<]+.*</dt>\s*<dd>\s*<div class="title">\s*<p class="heading">Rainy day</p>~s',
            $html,
        );

        // OpenPNE 3 ja: 「%1%さんの日記」 — no space before さん.
        $ja = $this->actingAs($owner)->withSession(['locale' => 'ja'])
            ->get("/diary/{$diary->getKey()}")->assertOk()->getContent();
        $this->assertStringContainsString('<h3>Aliceさんの日記</h3>', $ja);
    }

    public function test_markdown_bodies_scope_their_border_reset_above_the_plugin_css(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'body' => '**bold**',
            'format' => BodyFormat::Markdown,
        ]);

        $html = $this->actingAs($owner)->get("/diary/{$diary->getKey()}")->assertOk()->getContent();

        // diary.css's `.diaryDetailBox dd div` is specificity 0-1-2; a bare `.markdownBody` reset
        // loses to it regardless of order, so the override must carry the scoped selector.
        $this->assertStringContainsString('.diaryDetailBox dd div.markdownBody { border-top: none; }', $html);
    }

    public function test_renders_the_attached_images_ahead_of_the_body_text(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'body' => 'Attached above.']);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'number' => 1]);

        $html = $this->actingAs($owner)->get("/diary/{$diary->getKey()}")->assertOk()->getContent();

        $body = strpos($html, '<div class="body">');
        $photo = strpos($html, '<ul class="photo">');
        $text = strpos($html, 'Attached above.');

        $this->assertNotFalse($photo);
        $this->assertGreaterThan($body, $photo); // both inside the dd's body div
        $this->assertGreaterThan($photo, $text);
    }

    public function test_renders_the_ported_owner_edit_entry(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);

        $this->actingAs($owner)->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertSee("/diary/edit/{$diary->getKey()}", false)
            // OpenPNE 3's entry: a GET form to diary_edit in the operation area, not a bare link.
            ->assertSee('<div class="operation">', false)
            ->assertSee('<form action="'.route('diary.edit', $diary).'">', false)
            ->assertSee('class="input_submit"', false);
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
