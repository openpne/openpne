<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Models\DirectMessage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class ConversationVisibilityTest extends ConversationTestCase
{
    public function test_a_conversation_holds_both_directions_of_the_pair(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $other, ['body' => 'mine']);
        $this->deliver($other, $viewer, ['body' => 'theirs']);

        $page = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->assertOk()->json();

        $this->assertSame(['mine', 'theirs'], $this->bodies($page));
    }

    public function test_another_members_conversation_is_not_this_one(): void
    {
        [$viewer, $other, $third] = Member::factory()->count(3)->create();
        $this->deliver($viewer, $other, ['body' => 'ours']);
        $this->deliver($viewer, $third, ['body' => 'theirs']);
        $this->deliver($third, $viewer, ['body' => 'also theirs']);

        $page = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json();

        $this->assertSame(['ours'], $this->bodies($page));
    }

    /** A message the viewer never received is not in the conversation either, even from the same member. */
    public function test_a_message_addressed_to_someone_else_stays_out(): void
    {
        [$viewer, $other, $third] = Member::factory()->count(3)->create();
        $this->deliver($other, $third, ['body' => 'not for you']);

        $page = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json();

        $this->assertSame([], $this->bodies($page));
    }

    public function test_trashing_your_own_copy_takes_it_out_of_your_conversation_only(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        // Each side trashes one row: their own sent copy, and their own receipt.
        $this->deliver($viewer, $other, ['body' => 'sent, trashed by me', 'sender_deleted_at' => now()]);
        $this->deliver($viewer, $other, ['body' => 'sent, trashed by them'], ['recipient_deleted_at' => now()]);
        $this->deliver($other, $viewer, ['body' => 'received, trashed by me'], ['recipient_deleted_at' => now()]);
        $this->deliver($other, $viewer, ['body' => 'received, trashed by them', 'sender_deleted_at' => now()]);

        $mine = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json();
        $theirs = $this->actingAs($other)->getJson("/messages/{$viewer->getKey()}/messages")->json();

        $this->assertSame(['sent, trashed by them', 'received, trashed by them'], $this->bodies($mine));
        $this->assertSame(['sent, trashed by me', 'received, trashed by me'], $this->bodies($theirs));
    }

    public function test_purging_your_own_copy_takes_it_out_of_your_conversation_only(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $other, ['body' => 'sent, purged by me', 'sender_deleted_at' => now(), 'sender_purged_at' => now()]);
        $this->deliver($other, $viewer, ['body' => 'received, purged by me'], ['recipient_deleted_at' => now(), 'recipient_purged_at' => now()]);
        $this->deliver($viewer, $other, ['body' => 'still here']);

        $mine = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json();
        $theirs = $this->actingAs($other)->getJson("/messages/{$viewer->getKey()}/messages")->json();

        $this->assertSame(['still here'], $this->bodies($mine));
        $this->assertSame(['sent, purged by me', 'received, purged by me', 'still here'], $this->bodies($theirs));
    }

    /** These rows carry only the purge, which a check on the trash column alone would miss. */
    public function test_a_purge_without_a_trash_is_still_a_purge(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $other, ['body' => 'sent, purged only', 'sender_purged_at' => now()]);
        $this->deliver($other, $viewer, ['body' => 'received, purged only'], ['recipient_purged_at' => now()]);
        $this->deliver($viewer, $other, ['body' => 'still here']);

        $page = $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json();

        $this->assertSame(['still here'], $this->bodies($page));
    }

    /** A draft has no receipt, so neither arm reaches it — it stays the drafts box's. */
    public function test_a_draft_is_in_neither_sides_conversation(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        DirectMessage::factory()->draft()->create([
            'sender_id' => $viewer->getKey(),
            'draft_recipient_id' => $other->getKey(),
            'body' => 'unsent',
        ]);
        // Even a stray receipt on a draft row stays out: the arms test is_draft as well.
        $this->deliver($viewer, $other, ['is_draft' => true, 'body' => 'unsent with a receipt']);

        $this->assertSame([], $this->bodies($this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->json()));
        $this->assertSame([], $this->bodies($this->actingAs($other)->getJson("/messages/{$viewer->getKey()}/messages")->json()));
    }

    public function test_a_block_hides_nothing(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $other, ['body' => 'mine']);
        $this->deliver($other, $viewer, ['body' => 'theirs']);
        DB::table('member_blocks')->insert(['blocker_id' => $other->getKey(), 'blocked_id' => $viewer->getKey()]);

        $this->actingAs($viewer)
            ->get("/messages/{$other->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('message/conversation/index')
                ->has('page.messages', 2));
    }

    /**
     * Both member FKs are nullOnDelete, so a departed member leaves no id to key a conversation by;
     * every one of them collapses into the withdrawn bucket, in both directions.
     */
    public function test_the_withdrawn_bucket_holds_both_arms_and_nothing_else(): void
    {
        [$viewer, $leaving, $present] = Member::factory()->count(3)->create();
        $this->deliver($viewer, $leaving, ['body' => 'sent to someone who left']);
        $this->deliver($leaving, $viewer, ['body' => 'received from someone who left']);
        $this->deliver($viewer, $present, ['body' => 'still a member']);
        $leaving->delete();

        $withdrawn = $this->actingAs($viewer)->getJson('/messages/withdrawn/messages')->assertOk()->json();
        $this->assertSame(['sent to someone who left', 'received from someone who left'], $this->bodies($withdrawn));

        // And it is its own conversation: the live one holds only what is still addressed to a member.
        $live = $this->actingAs($viewer)->getJson("/messages/{$present->getKey()}/messages")->json();
        $this->assertSame(['still a member'], $this->bodies($live));
    }

    public function test_the_withdrawn_bucket_respects_the_viewers_own_trash(): void
    {
        [$viewer, $leaving] = Member::factory()->count(2)->create();
        $this->deliver($viewer, $leaving, ['body' => 'trashed by me', 'sender_deleted_at' => now()]);
        $this->deliver($leaving, $viewer, ['body' => 'kept']);
        $leaving->delete();

        $page = $this->actingAs($viewer)->getJson('/messages/withdrawn/messages')->json();

        $this->assertSame(['kept'], $this->bodies($page));
    }

    public function test_the_withdrawn_page_carries_no_counterpart(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)
            ->get('/messages/withdrawn')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('message/conversation/index')
                ->where('counterpart', null)
                ->has('page.messages', 0));
    }

    public function test_the_page_names_the_counterpart(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();

        $this->actingAs($viewer)
            ->get("/messages/{$other->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('counterpart.id', $other->getKey())
                ->where('counterpart.name', $other->name));
    }
}
