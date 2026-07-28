<?php

namespace Tests\Feature\Message\Classic;

use App\Models\Member;
use App\Models\MemberImage;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The message detail against OpenPNE 3 showSuccess.php: the counterpart's photo cell spanning the
 * From/To, date and subject rows — and only when there is exactly one counterpart to show.
 */
class MessageShowParityTest extends TestCase
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
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
        MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);

        $this->actingAs($recipient)->get(route('message.receive.show', ['message' => $message->getKey()]))
            ->assertOk()
            ->assertSee('<td class="photo" rowspan="3"><a href="'.route('member.profile.show', $sender).'">', false)
            ->assertSee('alt="'.$sender->name.'"', false);
    }

    public function test_a_message_with_several_recipients_shows_no_photo_cell(): void
    {
        $sender = Member::factory()->create();
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
        foreach (Member::factory()->count(2)->create() as $recipient) {
            $this->withAvatar($recipient);
            MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);
        }

        $this->actingAs($sender)->get(route('message.send.show', ['message' => $message->getKey()]))
            ->assertOk()
            ->assertDontSee('class="photo"', false);
    }

    public function test_a_withdrawn_counterpart_shows_no_photo_cell(): void
    {
        $recipient = Member::factory()->create();
        $message = Message::factory()->create(['sender_id' => null]);
        MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);

        $this->actingAs($recipient)->get(route('message.receive.show', ['message' => $message->getKey()]))
            ->assertOk()
            ->assertDontSee('class="photo"', false);
    }

    public function test_the_recipient_photos_are_eager_loaded(): void
    {
        $this->assertSame($this->queryCountFor(1), $this->queryCountFor(5));
    }

    /** Query count for the sent-box detail of a message addressed to $recipients members. */
    private function queryCountFor(int $recipients): int
    {
        $sender = Member::factory()->create();
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
        foreach (Member::factory()->count($recipients)->create() as $recipient) {
            $this->withAvatar($recipient);
            MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $recipient->getKey()]);
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
