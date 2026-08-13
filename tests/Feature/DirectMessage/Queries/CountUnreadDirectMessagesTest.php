<?php

namespace Tests\Feature\DirectMessage\Queries;

use App\Features\DirectMessage\Queries\CountUnreadDirectMessages;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CountUnreadDirectMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function deliver(Member $sender, Member $recipient, array $message = [], array $receipt = []): void
    {
        $m = DirectMessage::factory()->create([...['sender_id' => $sender->getKey()], ...$message]);
        DirectMessageRecipient::factory()->create([...['direct_message_id' => $m->getKey(), 'recipient_id' => $recipient->getKey()], ...$receipt]);
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

        $this->assertSame(2, (new CountUnreadDirectMessages)($viewer));
    }

    public function test_is_zero_without_messages(): void
    {
        $viewer = Member::factory()->create();

        $this->assertSame(0, (new CountUnreadDirectMessages)($viewer));
    }
}
