<?php

namespace Tests\Feature\DirectMessage\Classic;

use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The message box list against OpenPNE 3 listSuccess.php. */
class DirectMessageListParityTest extends TestCase
{
    use RefreshDatabase;

    private function deliver(Member $sender, Member $recipient, array $message = [], array $receipt = []): DirectMessage
    {
        $m = DirectMessage::factory()->create([...['sender_id' => $sender->getKey()], ...$message]);
        DirectMessageRecipient::factory()->create([...['direct_message_id' => $m->getKey(), 'recipient_id' => $recipient->getKey()], ...$receipt]);

        return $m;
    }

    private function icon(string $file): string
    {
        return 'src="'.asset('opMessagePlugin/images/'.$file).'"';
    }

    public function test_the_pager_brackets_the_table(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient);

        $body = (string) $this->actingAs($recipient)->get('/message/receiveList')->assertOk()->getContent();

        $this->assertSame(2, substr_count($body, 'class="pagerRelative"'));
        $this->assertLessThan(strpos($body, '<table>'), strpos($body, 'class="pagerRelative"'));
        $this->assertGreaterThan(strpos($body, '</table>'), strrpos($body, 'class="pagerRelative"'));
    }

    public function test_the_legend_band_carries_the_replied_icon_on_the_inbox_and_stays_empty_elsewhere(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient);

        // The legend spells the state out beside the icon, so the icon repeats nothing (alt="").
        $this->actingAs($recipient)->get('/message/receiveList')
            ->assertSee('<p class="icons"><span><img '.$this->icon('icon_mail_4.gif').' alt=""> Replied</span></p>', false);

        // The band is on every box (OpenPNE 3 emits the div unconditionally); only the inbox fills it.
        $this->actingAs($sender)->get('/message/sendList')
            ->assertSee('<div class="pagerRelativeMulti"></div>', false);
    }

    public function test_the_inbox_icon_reports_unread_read_and_replied(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $unread = $this->deliver($sender, $recipient, ['subject' => 'Unread one']);
        $read = $this->deliver($sender, $recipient, ['subject' => 'Read one'], ['read_at' => now()]);
        $answered = $this->deliver($sender, $recipient, ['subject' => 'Answered one'], ['read_at' => now()]);
        // The viewer's reply points back at it, which is what OpenPNE 3's is_hensin asks.
        DirectMessage::factory()->create(['sender_id' => $recipient->getKey(), 'parent_id' => $answered->getKey()]);

        $body = (string) $this->actingAs($recipient)->get('/message/receiveList')->assertOk()->getContent();

        $this->assertSame(1, substr_count($body, $this->icon('icon_mail_1.gif'))); // unread
        $this->assertSame(1, substr_count($body, $this->icon('icon_mail_2.gif'))); // read
        // The replied row plus the legend band above the table.
        $this->assertSame(2, substr_count($body, $this->icon('icon_mail_4.gif')));
        $this->assertStringContainsString('alt="Unread"', $body);
        $this->assertStringContainsString('alt="Read"', $body);

        $this->assertStringContainsString('class="unread"', $body);
        $this->assertSame(1, substr_count($body, 'class="unread"'));
    }

    public function test_a_draft_reply_does_not_mark_the_message_answered(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $message = $this->deliver($sender, $recipient, [], ['read_at' => now()]);
        DirectMessage::factory()->create(['sender_id' => $recipient->getKey(), 'parent_id' => $message->getKey(), 'is_draft' => true]);

        $body = (string) $this->actingAs($recipient)->get('/message/receiveList')->assertOk()->getContent();

        $this->assertStringContainsString($this->icon('icon_mail_2.gif'), $body);
        $this->assertSame(1, substr_count($body, $this->icon('icon_mail_4.gif'))); // the legend only
    }

    public function test_the_sent_and_draft_boxes_carry_their_own_icons(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient);
        DirectMessage::factory()->create(['sender_id' => $sender->getKey(), 'draft_recipient_id' => $recipient->getKey(), 'is_draft' => true]);

        $this->actingAs($sender)->get('/message/sendList')
            ->assertSee($this->icon('icon_mail_3.gif'), false)
            ->assertSee('alt="Sent Message"', false);

        $this->actingAs($sender)->get('/message/draftList')
            ->assertSee($this->icon('icon_mail_1.gif'), false)
            ->assertSee('alt="Drafts"', false);
    }

    public function test_a_trashed_row_is_labelled_by_the_box_it_came_from(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->deliver($other, $viewer, [], ['recipient_deleted_at' => now()]);
        $this->deliver($viewer, $other, ['sender_deleted_at' => now()]);
        DirectMessage::factory()->create([
            'sender_id' => $viewer->getKey(), 'draft_recipient_id' => $other->getKey(),
            'is_draft' => true, 'sender_deleted_at' => now(),
        ]);

        $body = (string) $this->actingAs($viewer)->get('/message/dustList')->assertOk()->getContent();

        // OpenPNE 3's trash icons name the origin box rather than a read state.
        $this->assertStringContainsString('alt="Inbox"', $body);
        $this->assertStringContainsString('alt="Sent Message"', $body);
        $this->assertStringContainsString('alt="Drafts"', $body);
        $this->assertSame(1, substr_count($body, $this->icon('icon_mail_2.gif')));
        $this->assertSame(1, substr_count($body, $this->icon('icon_mail_3.gif')));
        $this->assertSame(1, substr_count($body, $this->icon('icon_mail_1.gif')));
    }

    public function test_the_operation_block_offers_check_all_and_clear_all(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient);

        $body = (string) $this->actingAs($recipient)->get('/message/receiveList')->assertOk()->getContent();

        // OpenPNE 3's own labels — a visible parity element, held in both locales.
        $this->assertStringContainsString('>Check All</a> /', $body);
        $this->assertStringContainsString('>Clear All</a>', $body);
        $this->assertSame(2, substr_count($body, 'c.checked='));
        // The header checkbox they replace is gone; only the row boxes remain.
        $this->assertSame(1, substr_count($body, 'type="checkbox"'));

        $ja = (string) $this->actingAs($recipient)->withSession(['locale' => 'ja'])
            ->get('/message/receiveList')->assertOk()->getContent();
        $this->assertStringContainsString('>全てをチェック</a> /', $ja);
        $this->assertStringContainsString('>全てのチェックをはずす</a>', $ja);
    }

    public function test_the_table_declares_the_five_openpne3_columns(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $this->deliver($sender, $recipient);

        $response = $this->actingAs($recipient)->get('/message/receiveList')->assertOk();
        foreach (['status', 'delete', 'target', 'title', 'date'] as $column) {
            $response->assertSee('<col class="'.$column.'">', false);
        }
    }

    public function test_the_replied_lookup_costs_the_same_at_one_row_and_six(): void
    {
        $this->assertSame(
            $this->queryCountFor($this->inboxWithAnsweredMessages(1)),
            $this->queryCountFor($this->inboxWithAnsweredMessages(6)),
        );
    }

    /** A member whose inbox holds $count messages, each one they have replied to. */
    private function inboxWithAnsweredMessages(int $count): Member
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        foreach (range(1, $count) as $ignored) {
            $message = $this->deliver($sender, $recipient, [], ['read_at' => now()]);
            DirectMessage::factory()->create(['sender_id' => $recipient->getKey(), 'parent_id' => $message->getKey()]);
        }

        return $recipient;
    }

    private function queryCountFor(Member $viewer): int
    {
        $this->actingAs($viewer)->get('/message/receiveList')->assertOk(); // warm the process-wide caches
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->get('/message/receiveList')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
