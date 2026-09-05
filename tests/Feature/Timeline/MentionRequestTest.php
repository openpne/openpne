<?php

namespace Tests\Feature\Timeline;

use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The request half of the mention contract; a row that merely stopped matching is the storage half's. */
class MentionRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_post_carries_its_mentions_through_the_form(): void
    {
        $member = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);

        $this->actingAs($member)->post('/timeline/create', [
            'body' => 'hi @Alice',
            'visibility' => (string) Visibility::Members->value,
            'mentions' => [['member_id' => (string) $alice->getKey(), 'offset' => '3', 'length' => '6']],
        ])->assertRedirect(route('timeline.member', $member));

        $this->assertDatabaseHas('timeline_post_mentions', [
            'member_id' => $alice->getKey(),
            'offset' => 3,
            'length' => 6,
        ]);
    }

    public function test_a_reply_carries_its_mentions_through_the_form(): void
    {
        $member = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->post("/timeline/{$post->getKey()}/reply", [
            'body' => 'hi @Alice',
            'mentions' => [['member_id' => (string) $alice->getKey(), 'offset' => '3', 'length' => '6']],
        ])->assertRedirect("/timeline/{$post->getKey()}");

        $this->assertDatabaseHas('timeline_post_mentions', [
            'member_id' => $alice->getKey(),
            'offset' => 3,
            'length' => 6,
        ]);
    }

    public function test_a_non_integer_member_id_rejects_the_post(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->postJson('/timeline/create', [
            'body' => 'hi @Alice',
            'visibility' => (string) Visibility::Members->value,
            'mentions' => [['member_id' => 'alice', 'offset' => 3, 'length' => 6]],
        ])->assertStatus(422)->assertJsonValidationErrors('mentions.0.member_id');

        $this->assertDatabaseCount('timeline_posts', 0);
    }

    public function test_a_negative_offset_rejects_the_post(): void
    {
        $member = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);

        $this->actingAs($member)->postJson('/timeline/create', [
            'body' => 'hi @Alice',
            'visibility' => (string) Visibility::Members->value,
            'mentions' => [['member_id' => $alice->getKey(), 'offset' => -1, 'length' => 6]],
        ])->assertStatus(422)->assertJsonValidationErrors('mentions.0.offset');

        $this->assertDatabaseCount('timeline_posts', 0);
    }

    public function test_more_than_ten_mentions_rejects_the_post(): void
    {
        $member = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $mentions = array_fill(0, 11, ['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6]);

        $this->actingAs($member)->postJson('/timeline/create', [
            'body' => 'hi @Alice',
            'visibility' => (string) Visibility::Members->value,
            'mentions' => $mentions,
        ])->assertStatus(422)->assertJsonValidationErrors('mentions');

        $this->assertDatabaseCount('timeline_posts', 0);
    }

    public function test_a_missing_length_rejects_the_post(): void
    {
        $member = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);

        $this->actingAs($member)->postJson('/timeline/create', [
            'body' => 'hi @Alice',
            'visibility' => (string) Visibility::Members->value,
            'mentions' => [['member_id' => $alice->getKey(), 'offset' => 3]],
        ])->assertStatus(422)->assertJsonValidationErrors('mentions.0.length');

        $this->assertDatabaseCount('timeline_posts', 0);
    }

    public function test_submitted_crlf_newlines_are_normalized_before_offsets_are_checked(): void
    {
        $member = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);

        // The client counted "line1\n@Alice", where the handle starts at 6; the browser submits CRLF.
        $this->actingAs($member)->post('/timeline/create', [
            'body' => "line1\r\n@Alice",
            'visibility' => (string) Visibility::Members->value,
            'mentions' => [['member_id' => $alice->getKey(), 'offset' => 6, 'length' => 6]],
        ])->assertRedirect(route('timeline.member', $member));

        $this->assertDatabaseHas('timeline_posts', ['body' => "line1\n@Alice"]);
        $this->assertDatabaseHas('timeline_post_mentions', ['member_id' => $alice->getKey(), 'offset' => 6]);
    }

    public function test_an_offset_is_counted_in_code_points_past_an_astral_emoji(): void
    {
        $member = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);

        // U+1F600 is one code point but two UTF-16 units and four bytes: only the code-point count
        // puts the handle at 5.
        $this->actingAs($member)->post('/timeline/create', [
            'body' => '😀 hi @Alice',
            'visibility' => (string) Visibility::Members->value,
            'mentions' => [['member_id' => $alice->getKey(), 'offset' => 5, 'length' => 6]],
        ])->assertRedirect(route('timeline.member', $member));

        $this->assertDatabaseHas('timeline_post_mentions', ['member_id' => $alice->getKey(), 'offset' => 5]);
    }

    public function test_a_body_of_140_astral_code_points_is_accepted(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->postJson('/timeline/create', [
            'body' => str_repeat('😀', 140),
            'visibility' => (string) Visibility::Members->value,
        ])->assertRedirect(route('timeline.member', $member));

        $this->assertDatabaseCount('timeline_posts', 1);
    }

    public function test_a_body_of_141_astral_code_points_is_rejected(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->postJson('/timeline/create', [
            'body' => str_repeat('😀', 141),
            'visibility' => (string) Visibility::Members->value,
        ])->assertStatus(422)->assertJsonValidationErrors('body');

        $this->assertDatabaseCount('timeline_posts', 0);
    }
}
