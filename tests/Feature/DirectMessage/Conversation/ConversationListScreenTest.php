<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Models\DirectMessage;
use App\Models\DirectMessageFile;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/** The `/messages` screen: the conversations, and the drafts box carried under them. */
class ConversationListScreenTest extends ConversationTestCase
{
    private function at(?Member $sender, ?Member $recipient, string $at, array $message = []): DirectMessage
    {
        $when = Carbon::parse($at);

        return $this->deliver($sender, $recipient, ['created_at' => $when, 'updated_at' => $when, ...$message]);
    }

    private function draft(Member $author, Member $recipient, string $subject): DirectMessage
    {
        return DirectMessage::factory()->draft()->create([
            'sender_id' => $author->getKey(),
            'draft_recipient_id' => $recipient->getKey(),
            'subject' => $subject,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/messages')->assertRedirect('/login');
    }

    public function test_the_list_renders_each_conversation_with_its_preview_and_unread(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->at($other, $viewer, '2026-08-14 09:00:00', ['body' => 'first']);
        $this->at($other, $viewer, '2026-08-14 09:01:00', ['body' => "the\nlast   word"]);

        $this->actingAs($viewer)->get('/messages')
            ->assertInertia(fn ($page) => $page
                ->component('message/conversations/index')
                ->has('conversations.data', 1)
                ->where('conversations.data.0.counterpart.id', $other->getKey())
                ->where('conversations.data.0.unread', 2)
                // One line: the row clips it, and a multi-line body must not grow it.
                ->where('conversations.data.0.latest.body', 'the last word')
                ->has('drafts.data', 0)
            );
    }

    public function test_the_withdrawn_bucket_has_no_counterpart_to_name(): void
    {
        [$viewer, $gone] = Member::factory()->count(2)->create();
        $this->at($gone, $viewer, '2026-08-14 09:00:00', ['body' => 'from someone gone']);
        $gone->delete();

        $this->actingAs($viewer)->get('/messages')
            ->assertInertia(fn ($page) => $page
                ->has('conversations.data', 1)
                ->where('conversations.data.0.counterpart', null)
            );
    }

    /** A body-less message still has to say something; the mailbox's subject is what it has. */
    public function test_the_preview_falls_back_to_the_subject(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->at($other, $viewer, '2026-08-14 09:00:00', ['subject' => 'Only a subject', 'body' => '']);

        $this->actingAs($viewer)->get('/messages')
            ->assertInertia(fn ($page) => $page->where('conversations.data.0.latest.body', 'Only a subject'));
    }

    /** Last in the order: a message with neither words nor a subject is one with nothing but pictures. */
    public function test_the_preview_falls_back_to_a_picture(): void
    {
        Notification::fake();
        [$viewer, $other] = Member::factory()->count(2)->create();

        $this->actingAs($other)
            ->post("/messages/{$viewer->getKey()}", ['images' => [UploadedFile::fake()->image('shot.png', 40, 40)]])
            ->assertCreated();

        $this->actingAs($viewer)->get('/messages')
            ->assertInertia(fn ($page) => $page->where('conversations.data.0.latest.body', __('Image')));
    }

    /** The subject outranks the stand-in: a mailbox message that carries one has words of its own. */
    public function test_a_subject_outranks_the_picture_stand_in(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $message = $this->at($other, $viewer, '2026-08-14 09:00:00', ['subject' => 'Holiday photos', 'body' => '']);
        DirectMessageFile::factory()->create(['direct_message_id' => $message->getKey()]);

        $this->actingAs($viewer)->get('/messages')
            ->assertInertia(fn ($page) => $page->where('conversations.data.0.latest.body', 'Holiday photos'));
    }

    /**
     * "0" is a message, and so is a subject of "0". Each step down the body → subject → picture order
     * tests emptiness strictly, not PHP's truthiness, or a member's own words would read as a picture.
     */
    public function test_a_body_or_subject_of_zero_is_previewed_as_itself(): void
    {
        [$viewer, $other, $third] = Member::factory()->count(3)->create();
        $body = $this->at($other, $viewer, '2026-08-14 09:00:00', ['body' => '0']);
        DirectMessageFile::factory()->create(['direct_message_id' => $body->getKey()]);
        $subject = $this->at($third, $viewer, '2026-08-14 08:00:00', ['subject' => '0', 'body' => '']);
        DirectMessageFile::factory()->create(['direct_message_id' => $subject->getKey()]);

        $this->actingAs($viewer)->get('/messages')
            ->assertInertia(fn ($page) => $page
                ->where('conversations.data.0.latest.body', '0')
                ->where('conversations.data.1.latest.body', '0'));
    }

    public function test_drafts_ride_under_the_list(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->draft($viewer, $other, 'Half written');

        $this->actingAs($viewer)->get('/messages')
            ->assertInertia(fn ($page) => $page
                ->has('conversations.data', 0)
                ->has('drafts.data', 1)
                ->where('drafts.data.0.subject', 'Half written')
                ->where('drafts.data.0.counterparty.id', $other->getKey())
            );
    }

    /** Two pagers on one screen: each reads its own parameter, so paging one never moves the other. */
    public function test_the_two_pagers_are_independent(): void
    {
        $viewer = Member::factory()->create();
        foreach (range(1, 25) as $i) {
            $other = Member::factory()->create(['name' => "other {$i}"]);
            $this->at($other, $viewer, '2026-08-14 09:00:00', ['body' => "conversation {$i}"]);
            $this->draft($viewer, $other, "draft {$i}");
        }

        $this->actingAs($viewer)->get('/messages')
            ->assertInertia(fn ($page) => $page
                ->where('conversations.meta.currentPage', 1)
                ->has('conversations.data', 20)
                ->where('drafts.meta.currentPage', 1)
                ->has('drafts.data', 20)
            );

        $this->actingAs($viewer)->get('/messages?draft_page=2')
            ->assertInertia(fn ($page) => $page
                ->where('conversations.meta.currentPage', 1)
                ->has('conversations.data', 20)
                ->where('drafts.meta.currentPage', 2)
                ->has('drafts.data', 5)
            );

        $this->actingAs($viewer)->get('/messages?page=2')
            ->assertInertia(fn ($page) => $page
                ->where('conversations.meta.currentPage', 2)
                ->has('conversations.data', 5)
                ->where('drafts.meta.currentPage', 1)
                ->has('drafts.data', 20)
            );

        // Both at once: neither pager reads the other's number.
        $this->actingAs($viewer)->get('/messages?page=2&draft_page=2')
            ->assertInertia(fn ($page) => $page
                ->has('conversations.data', 5)
                ->has('drafts.data', 5)
            );
    }

    public function test_the_list_answers_404_while_the_unit_is_off(): void
    {
        $viewer = Member::factory()->create();
        $this->setSnsSetting(Feature::DirectMessage->settingKey(), false);

        $this->actingAs($viewer)->get('/messages')->assertNotFound();
    }
}
