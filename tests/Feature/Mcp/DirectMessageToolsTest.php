<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Features\DirectMessage\Queries\ConversationList;
use App\Mcp\McpAbilities;
use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\ListConversationsTool;
use App\Mcp\Tools\MarkDirectMessagesReadTool;
use App\Mcp\Tools\ReadDirectMessagesTool;
use App\Mcp\Tools\SendDirectMessageTool;
use App\Models\DirectMessage;
use App\Models\DirectMessageFile;
use App\Models\DirectMessageRecipient;
use App\Models\File;
use App\Models\Member;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use App\Support\SnsSettingKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CapturesSecurityLog;

class DirectMessageToolsTest extends McpTestCase
{
    use CapturesSecurityLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::FeatureDirectMessageEnabled, true);
    }

    private function acting(Member $member, array $abilities = [McpAbilities::READ, McpAbilities::WRITE]): Member
    {
        return Sanctum::actingAs($member, $abilities);
    }

    private function deliver(Member $sender, Member $recipient, string $body, ?Carbon $at = null): DirectMessage
    {
        $at ??= now();
        $row = DirectMessage::factory()->create([
            'sender_id' => $sender->getKey(), 'body' => $body, 'subject' => null, 'created_at' => $at, 'updated_at' => $at,
        ]);
        DirectMessageRecipient::factory()->create(['direct_message_id' => $row->getKey(), 'recipient_id' => $recipient->getKey()]);

        return $row;
    }

    public function test_sending_writes_a_chat_message_and_notifies_the_recipient(): void
    {
        Notification::fake();
        $owner = Member::factory()->create();
        $agent = Member::factory()->aiAccount($owner)->create();
        $this->acting($agent);

        OpenPneServer::tool(SendDirectMessageTool::class, ['member_id' => $owner->getKey(), 'body' => "  morning\r\nthree things  "])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('memberId', $owner->getKey())
                ->where('message.body', "morning\nthree things")
                ->where('message.isOwn', true)
                ->where('message.read', false)
                ->where('message.authorIsAi', true)
                ->etc());

        $this->assertDatabaseHas('direct_messages', [
            'sender_id' => $agent->getKey(), 'body' => "morning\nthree things", 'subject' => null, 'is_draft' => false,
        ]);
        Notification::assertSentTo($owner, DirectMessageReceivedNotification::class);
    }

    public function test_an_ai_account_may_write_to_its_owner_and_to_whoever_wrote_first_but_not_to_strangers(): void
    {
        Notification::fake();
        $owner = Member::factory()->create();
        $agent = Member::factory()->aiAccount($owner)->create();
        [$stranger, $asker] = Member::factory()->count(2)->create();
        $this->acting($agent);

        OpenPneServer::tool(SendDirectMessageTool::class, ['member_id' => $stranger->getKey(), 'body' => 'hello'])
            ->assertHasErrors(['No such conversation']);
        $this->assertDatabaseMissing('direct_messages', ['body' => 'hello']);

        // Once someone writes to the agent, it may answer them.
        $this->deliver($asker, $agent, 'a question');

        OpenPneServer::tool(SendDirectMessageTool::class, ['member_id' => $asker->getKey(), 'body' => 'an answer'])
            ->assertOk();

        // Only them: someone else having written, or the agent's own message to a member, opens nobody.
        $this->deliver($agent, $stranger, 'sent some other way');

        OpenPneServer::tool(SendDirectMessageTool::class, ['member_id' => $stranger->getKey(), 'body' => 'hello again'])
            ->assertHasErrors(['No such conversation']);

        // A person's token is not narrowed: choosing whom to write to is theirs.
        $this->acting($stranger);
        OpenPneServer::tool(SendDirectMessageTool::class, ['member_id' => $owner->getKey(), 'body' => 'from a person'])
            ->assertOk();
    }

    public function test_a_blocked_pair_yourself_and_a_member_naming_nobody_are_refused_alike(): void
    {
        [$viewer, $blocker] = Member::factory()->count(2)->create();
        DB::table('member_blocks')->insert(['blocker_id' => $blocker->getKey(), 'blocked_id' => $viewer->getKey(), 'created_at' => now()]);
        $this->acting($viewer);

        foreach ([$blocker->getKey(), $viewer->getKey(), $blocker->getKey() + 9999] as $memberId) {
            OpenPneServer::tool(SendDirectMessageTool::class, ['member_id' => $memberId, 'body' => 'hi'])
                ->assertHasErrors(['No such conversation']);
        }

        $this->assertDatabaseCount('direct_messages', 0);
    }

    public function test_sends_are_counted_per_member_before_the_recipient_is_looked_at(): void
    {
        Notification::fake();
        $this->captureSecurityLog();
        config(['openpne.throttle.direct_message' => 2]);
        [$sender, $other, $bystander] = Member::factory()->count(3)->create();

        $this->acting($sender);
        foreach (['one', 'two'] as $body) {
            OpenPneServer::tool(SendDirectMessageTool::class, ['member_id' => $other->getKey(), 'body' => $body])->assertOk();
        }

        // Another token of the same member, naming nobody: the budget is the member's, and the answer
        // is the limit's rather than the refusal a missing member gets.
        $this->acting($sender);
        OpenPneServer::tool(SendDirectMessageTool::class, ['member_id' => $other->getKey() + 9999, 'body' => 'three'])
            ->assertHasErrors(['Too many direct messages']);

        $this->assertDatabaseCount('direct_messages', 2);
        $logged = $this->assertOneSecurityEvent('throttle.hit');
        $this->assertEquals($sender->getKey(), $logged['member_id']);
        $this->assertSame('send-direct-message', $logged['tool']);

        $this->acting($bystander);
        OpenPneServer::tool(SendDirectMessageTool::class, ['member_id' => $other->getKey(), 'body' => 'mine'])->assertOk();
    }

    public function test_a_read_only_token_is_told_which_ability_it_is_missing(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->acting($viewer, [McpAbilities::READ]);

        OpenPneServer::tool(SendDirectMessageTool::class, ['member_id' => $other->getKey(), 'body' => 'nope'])
            ->assertHasErrors([McpAbilities::WRITE]);
        OpenPneServer::tool(MarkDirectMessagesReadTool::class, ['member_id' => $other->getKey(), 'message_id' => 1])
            ->assertHasErrors([McpAbilities::WRITE]);

        OpenPneServer::tool(ListConversationsTool::class, [])->assertOk();
    }

    public function test_the_list_names_each_counterpart_with_the_callers_unread(): void
    {
        [$viewer, $alice, $bob] = Member::factory()->count(3)->create();
        $this->deliver($alice, $viewer, 'from alice', now()->subMinutes(5));
        $this->deliver($alice, $viewer, 'again from alice', now()->subMinutes(4));
        $this->deliver($viewer, $bob, 'to bob', now()->subMinute());
        $this->acting($viewer);

        OpenPneServer::tool(ListConversationsTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('total', 2)
                ->where('conversations.0.memberId', $bob->getKey())
                ->where('conversations.0.unread', 0)
                ->where('conversations.0.preview', 'to bob')
                ->where('conversations.1.memberId', $alice->getKey())
                ->where('conversations.1.unread', 2)
                ->etc());
    }

    public function test_the_list_pages_by_argument_and_names_nobody_for_the_withdrawn_bucket(): void
    {
        $viewer = Member::factory()->create();
        foreach (Member::factory()->count(ConversationList::PER_PAGE)->create() as $i => $other) {
            $this->deliver($other, $viewer, 'hi', now()->subMinutes($i + 1));
        }
        $left = $this->deliver(Member::factory()->create(), $viewer, 'before leaving', now()->subHour());
        DB::table('direct_messages')->where('id', $left->getKey())->update(['sender_id' => null]);
        $this->acting($viewer);

        OpenPneServer::tool(ListConversationsTool::class, ['page' => 2])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('page', 2)
                ->where('lastPage', 2)
                ->has('conversations', 1)
                ->where('conversations.0.memberId', null)
                ->where('conversations.0.name', null)
                ->where('conversations.0.unread', 1)
                ->etc());
    }

    public function test_a_page_reports_the_counterparts_receipt_and_the_files_a_message_carries(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $opened = $this->deliver($viewer, $other, 'opened', now()->subMinutes(3));
        $opened->recipients()->update(['read_at' => now()]);
        $attached = $this->deliver($other, $viewer, '', now()->subMinutes(2));
        DirectMessageFile::factory()->create([
            'direct_message_id' => $attached->getKey(),
            'file_id' => File::factory()->create(['type' => 'application/pdf']),
        ]);
        $this->deliver($viewer, $other, 'latest', now()->subMinute());
        $this->acting($viewer);

        $cursor = null;
        OpenPneServer::tool(ReadDirectMessagesTool::class, ['member_id' => $other->getKey()])
            ->assertOk()
            ->assertStructuredContent(function ($json) use (&$cursor): void {
                $json->where('messages.0.read', true)
                    ->where('messages.1.hasImages', false)
                    ->where('messages.1.fileCount', 1)
                    ->where('messages.2.read', false)
                    ->etc();
                $cursor = $json->toArray()['messages'][2]['cursor'];
            });

        OpenPneServer::tool(ReadDirectMessagesTool::class, ['member_id' => $other->getKey(), 'mode' => 'before', 'cursor' => $cursor])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->has('messages', 2)
                ->where('messages.0.body', 'opened')
                ->where('messages.1.fileCount', 1)
                ->etc());
    }

    public function test_reading_pages_a_conversation_and_marks_nothing_read(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $first = $this->deliver($other, $viewer, 'first', now()->subMinutes(2));
        $this->deliver($viewer, $other, 'second', now()->subMinute());
        $this->acting($viewer);

        $cursor = null;
        OpenPneServer::tool(ReadDirectMessagesTool::class, ['member_id' => $other->getKey()])
            ->assertOk()
            ->assertStructuredContent(function ($json) use (&$cursor, $other): void {
                $json->where('memberId', $other->getKey())
                    ->where('messages.0.body', 'first')->where('messages.0.isOwn', false)->where('messages.0.read', null)
                    ->where('messages.1.body', 'second')->where('messages.1.isOwn', true)
                    ->etc();
                $cursor = $json->toArray()['nextCursor'];
            });

        $this->assertNull($first->recipients()->first()->read_at);

        $this->deliver($other, $viewer, 'third');

        OpenPneServer::tool(ReadDirectMessagesTool::class, ['member_id' => $other->getKey(), 'mode' => 'after', 'cursor' => $cursor])
            ->assertOk()
            ->assertSee('third')
            ->assertDontSee('"first"');

        OpenPneServer::tool(ReadDirectMessagesTool::class, ['member_id' => $other->getKey(), 'mode' => 'after', 'cursor' => 'not-a-cursor'])
            ->assertHasErrors(['No such conversation']);
    }

    public function test_marking_read_moves_the_callers_receipts_up_to_the_message(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $older = $this->deliver($other, $viewer, 'older', now()->subMinutes(2));
        $newer = $this->deliver($other, $viewer, 'newer', now()->subMinute());
        $elsewhere = $this->deliver(Member::factory()->create(), $viewer, 'another conversation');
        $this->acting($viewer);

        OpenPneServer::tool(MarkDirectMessagesReadTool::class, ['member_id' => $other->getKey(), 'message_id' => $older->getKey()])
            ->assertOk();

        $this->assertNotNull($older->recipients()->first()->read_at);
        $this->assertNull($newer->recipients()->first()->read_at);

        OpenPneServer::tool(MarkDirectMessagesReadTool::class, ['member_id' => $other->getKey(), 'message_id' => $elsewhere->getKey()])
            ->assertHasErrors(['No such conversation']);
    }

    public function test_marking_read_clears_the_feed_row_the_message_raised(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->acting($other);
        OpenPneServer::tool(SendDirectMessageTool::class, ['member_id' => $viewer->getKey(), 'body' => 'ping'])->assertOk();
        $this->assertSame(1, $viewer->unreadNotifications()->count());

        $this->acting($viewer);
        OpenPneServer::tool(MarkDirectMessagesReadTool::class, [
            'member_id' => $other->getKey(), 'message_id' => DirectMessage::query()->sole()->getKey(),
        ])->assertOk();

        $this->assertSame(0, $viewer->unreadNotifications()->count());
    }

    public function test_switching_direct_messages_off_takes_the_tools_away(): void
    {
        $this->setSnsSetting(SnsSettingKey::FeatureDirectMessageEnabled, false);

        $this->assertFalse((new ListConversationsTool)->shouldRegister());
        $this->assertFalse((new SendDirectMessageTool)->shouldRegister());
    }
}
