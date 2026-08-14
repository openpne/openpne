<?php

namespace Tests\Feature\DirectMessage\Classic;

use App\Models\DirectMessage;
use App\Models\DirectMessageFile;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use App\Models\MemberImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The message detail against OpenPNE 3 showSuccess.php: the counterpart's photo cell spanning the
 * From/To, date and subject rows — and only when there is exactly one counterpart to show.
 */
class DirectMessageShowParityTest extends TestCase
{
    use RefreshDatabase;

    private function withAvatar(Member $member): Member
    {
        MemberImage::factory()->create(['member_id' => $member->getKey()]);

        return $member;
    }

    public function test_a_one_to_one_message_spans_the_counterparts_photo_over_the_three_rows(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->withAvatar($sender);
        $message = DirectMessage::factory()->create(['sender_id' => $sender->getKey()]);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);

        $this->actingAs($recipient)->get(route('message.receive.show', ['message' => $message->getKey()]))
            ->assertOk()
            ->assertSee('<td class="photo" rowspan="3"><a href="'.route('member.profile.show', $sender).'">', false)
            ->assertSee('alt="'.$sender->name.'"', false);
    }

    public function test_a_message_with_several_recipients_shows_no_photo_cell(): void
    {
        $sender = Member::factory()->create();
        $message = DirectMessage::factory()->create(['sender_id' => $sender->getKey()]);
        foreach (Member::factory()->count(2)->create() as $recipient) {
            $this->withAvatar($recipient);
            DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);
        }

        $this->actingAs($sender)->get(route('message.send.show', ['message' => $message->getKey()]))
            ->assertOk()
            ->assertDontSee('class="photo"', false);
    }

    public function test_a_withdrawn_counterpart_shows_no_photo_cell(): void
    {
        $recipient = Member::factory()->create();
        $message = DirectMessage::factory()->create(['sender_id' => null]);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);

        $this->actingAs($recipient)->get(route('message.receive.show', ['message' => $message->getKey()]))
            ->assertOk()
            ->assertDontSee('class="photo"', false);
    }

    /**
     * A message written as chat may be nothing but pictures, and the mailbox shows it too. The
     * paragraph is left out rather than drawn empty — its height would read as a body that is there.
     */
    public function test_a_picture_only_message_draws_no_empty_paragraph(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = DirectMessage::factory()->create(['sender_id' => $sender->getKey(), 'subject' => null, 'body' => '']);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);
        DirectMessageFile::factory()->create(['direct_message_id' => $message->getKey()]);

        $response = $this->actingAs($recipient)->get(route('message.receive.show', ['message' => $message->getKey()]))->assertOk();

        $response->assertDontSee('<p class="text">', false);
        // The pictures are still there — this is about the missing words, not a hidden message.
        $response->assertSee('<ul class="photo">', false);
    }

    public function test_the_recipient_photos_are_eager_loaded(): void
    {
        $this->assertSame($this->queryCountFor(1), $this->queryCountFor(5));
    }

    /** Query count for the sent-box detail of a message addressed to $recipients members. */
    private function queryCountFor(int $recipients): int
    {
        $sender = Member::factory()->create();
        $message = DirectMessage::factory()->create(['sender_id' => $sender->getKey()]);
        foreach (Member::factory()->count($recipients)->create() as $recipient) {
            $this->withAvatar($recipient);
            DirectMessageRecipient::factory()->create(['direct_message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);
        }
        $url = route('message.send.show', ['message' => $message->getKey()]);

        $this->actingAs($sender)->get($url)->assertOk(); // warm the process-wide caches
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($sender)->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
