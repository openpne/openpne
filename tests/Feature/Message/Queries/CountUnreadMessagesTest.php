<?php

namespace Tests\Feature\Message\Queries;

use App\Features\Message\Queries\CountUnreadMessages;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CountUnreadMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function deliver(Member $sender, Member $recipient, array $message = [], array $receipt = []): void
    {
        $m = Message::factory()->create([...['sender_id' => $sender->getKey()], ...$message]);
        MessageRecipient::factory()->create([...['message_id' => $m->getKey(), 'recipient_id' => $recipient->getKey()], ...$receipt]);
    }

    public function test_counts_only_live_unread_delivered_receipts_for_the_viewer(): void
    {
        [$sender, $viewer, $other] = Member::factory()->count(3)->create()->all();

        $this->deliver($sender, $viewer);                                                  // unread: counts
        $this->deliver($sender, $viewer);                                                  // unread: counts
        $this->deliver($sender, $viewer, receipt: ['read_at' => now()]);                   // read: excluded
        $this->deliver($sender, $viewer, ['is_draft' => true]);                            // draft: excluded
        $this->deliver($sender, $viewer, receipt: ['recipient_deleted_at' => now()]);      // trashed: excluded
        $this->deliver($sender, $viewer, receipt: ['recipient_purged_at' => now()]);       // purged: excluded
        $this->deliver($sender, $other);                                                   // someone else: excluded

        $this->assertSame(2, (new CountUnreadMessages)($viewer));
    }

    public function test_is_zero_without_messages(): void
    {
        $viewer = Member::factory()->create();

        $this->assertSame(0, (new CountUnreadMessages)($viewer));
    }
}
