<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Features\GroupTalk\Actions\CreateGroupMessage;
use App\Features\GroupTalk\Events\GroupMessagePosted;
use App\Features\GroupTalk\GroupTalkPermissions;
use App\Features\GroupTalk\GroupTalkRoomNotificationRows;
use App\Features\GroupTalk\Queries\GroupTalkMessages;
use App\Features\GroupTalk\Queries\ReplyReferences;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Features\GroupTalk\TalkBody;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Timeline\Actions\ResolveMentions;
use App\Files\PostImages;
use App\Mcp\McpAbilities;
use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\ListTalkRoomsTool;
use App\Mcp\Tools\MarkTalkReadTool;
use App\Mcp\Tools\PostTalkMessageTool;
use App\Mcp\Tools\ReadTalkMessagesTool;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Notifications\GroupTalk\GroupTalkMentionedNotification;
use App\Support\Feature;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The four talk tools, called the way an MCP client calls them: the token decides who the caller is,
 * and every refusal a room can produce comes back as the one message that names nothing.
 */
class TalkToolsTest extends McpTestCase
{
    /** Sign in as $member with the abilities their token carries. */
    private function acting(Member $member, array $abilities = [McpAbilities::READ, McpAbilities::WRITE]): Member
    {
        return Sanctum::actingAs($member, $abilities);
    }

    public function test_the_room_list_is_the_callers_own_rooms_newest_conversation_first(): void
    {
        $quiet = $this->group();
        $busy = $this->group();
        $elsewhere = $this->group();

        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $quiet->getKey(), 'member_id' => $member->getKey()]);
        GroupMember::factory()->create(['group_id' => $busy->getKey(), 'member_id' => $member->getKey()]);

        $other = $this->memberOf($busy);
        $this->say($busy, $other, 'hello');
        $this->say($elsewhere, $this->memberOf($elsewhere), 'not yours');

        $this->acting($member);

        OpenPneServer::tool(ListTalkRoomsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('rooms.0.groupId', $busy->getKey())
                ->where('rooms.0.unread', 1)
                ->where('rooms.1.groupId', $quiet->getKey())
                ->where('rooms.1.unread', 0)
                ->where('rooms.1.lastMessageAt', null)
                ->where('total', 2)
                ->etc());
    }

    public function test_the_room_list_pages_where_it_is_told_to(): void
    {
        $member = Member::factory()->create();
        foreach (range(1, 21) as $ignored) {
            GroupMember::factory()->create([
                'group_id' => $this->group()->getKey(),
                'member_id' => $member->getKey(),
            ]);
        }

        $this->acting($member);

        // Page two exists at all only because the tool names the page: there is no URL here for the
        // paginator's own resolver to read one off.
        OpenPneServer::tool(ListTalkRoomsTool::class, ['page' => 2])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('page', 2)->where('lastPage', 2)->count('rooms', 1)->etc());
    }

    public function test_reading_a_room_the_caller_may_not_read_is_refused_without_saying_it_exists(): void
    {
        $private = $this->group(TopicReadAccess::MembersOnly);
        $this->say($private, $this->memberOf($private), 'members only');

        $this->acting(Member::factory()->create());

        $refusals = [
            OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $private->getKey()]),
            // A group id that names nothing at all answers exactly the same.
            OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $private->getKey() + 9999]),
        ];

        foreach ($refusals as $response) {
            $response->assertHasErrors(['No such talk room'])->assertDontSee('members only');
        }
    }

    public function test_the_newest_page_is_capped_and_walks_back_by_cursor(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $author = $this->memberOf($group);

        foreach (range(1, GroupTalkMessages::PER_PAGE + 3) as $n) {
            $this->say($group, $author, "line {$n}");
        }

        $this->acting($member);

        $latest = OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $group->getKey()]);
        $latest->assertOk()->assertSee('line 53')->assertDontSee('"line 3"');

        $cursor = null;
        $latest->assertStructuredContent(function ($json) use (&$cursor): void {
            $json->where('hasOlder', true)->where('hasNewer', false)->count('messages', GroupTalkMessages::PER_PAGE)->etc();
            $cursor = $json->toArray()['previousCursor'];
        });

        OpenPneServer::tool(ReadTalkMessagesTool::class, [
            'group_id' => $group->getKey(),
            'mode' => 'before',
            'cursor' => $cursor,
        ])->assertOk()->assertSee('line 1')->assertDontSee('line 53');
    }

    public function test_after_returns_only_what_arrived_since_the_cursor(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $author = $this->memberOf($group);
        $first = $this->say($group, $author, 'before the cursor');

        $this->acting($member);

        $cursor = null;
        OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $group->getKey()])
            ->assertStructuredContent(function ($json) use (&$cursor): void {
                $cursor = $json->toArray()['nextCursor'];
                $json->etc();
            });

        $this->say($group, $author, 'after the cursor');

        OpenPneServer::tool(ReadTalkMessagesTool::class, [
            'group_id' => $group->getKey(),
            'mode' => 'after',
            'cursor' => $cursor,
        ])
            ->assertOk()
            ->assertSee('after the cursor')
            ->assertDontSee('before the cursor')
            ->assertStructuredContent(fn ($json) => $json->count('messages', 1)->etc());

        $this->assertNotSame((string) $first->getKey(), $cursor);
    }

    public function test_before_and_after_need_a_cursor_and_refuse_one_that_does_not_parse(): void
    {
        $group = $this->group();
        $this->acting($this->memberOf($group));
        $this->app->setLocale('en');

        // Missing: the schema says required_if, so this is a validation error naming the field.
        OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $group->getKey(), 'mode' => 'before'])
            ->assertHasErrors(['cursor']);

        // Present but not a cursor this server issued: the same refusal a missing room gets, so a
        // caller cannot probe the encoding.
        OpenPneServer::tool(ReadTalkMessagesTool::class, [
            'group_id' => $group->getKey(),
            'mode' => 'after',
            'cursor' => 'not-a-cursor',
        ])->assertHasErrors(['No such talk room']);
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        $group = $this->group();
        $this->acting($this->memberOf($group));
        $this->app->setLocale('en');

        OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $group->getKey(), 'mode' => 'sideways'])
            ->assertHasErrors(['mode']);
    }

    public function test_a_withdrawn_author_reads_as_no_author_rather_than_a_hole(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        GroupMessage::factory()->withdrawnAuthor()->create([
            'group_id' => $group->getKey(),
            'body' => 'still here',
        ]);

        $this->acting($member);

        OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $group->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('messages.0.body', 'still here')
                ->where('messages.0.authorId', null)
                ->where('messages.0.authorName', null)
                ->where('messages.0.authorIsAi', false)
                ->etc());
    }

    public function test_an_ai_authors_message_says_so(): void
    {
        // The chip a reader sees, as a field: a reading agent must be able to tell a colleague's
        // words from another agent's without inferring it from the name.
        $group = $this->group();
        $member = $this->memberOf($group);
        $aiAccount = Member::factory()->aiAccount($member)->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $aiAccount->getKey()]);

        $this->say($group, $member, 'from a person');
        $this->say($group, $aiAccount, 'from an agent');

        $this->acting($member);

        OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $group->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('messages.0.authorIsAi', false)
                ->where('messages.1.authorName', $aiAccount->name)
                ->where('messages.1.authorIsAi', true)
                ->etc());
    }

    public function test_an_attachment_is_reported_as_a_count_and_never_as_a_url(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = $this->say($group, $member, 'look');
        $file = File::factory()->create(['type' => 'image/png']);
        DB::table('group_message_images')->insert([
            'group_message_id' => $message->getKey(),
            'file_id' => $file->getKey(),
            'number' => 1,
        ]);

        $this->acting($member);

        OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $group->getKey()])
            ->assertOk()
            ->assertDontSee('/file/')
            ->assertStructuredContent(fn ($json) => $json
                ->where('messages.0.hasImages', true)
                ->where('messages.0.imageCount', 1)
                ->etc());
    }

    public function test_posting_writes_the_message_fires_the_event_and_answers_with_the_row(): void
    {
        Event::fake([GroupMessagePosted::class]);

        $group = $this->group();
        $member = $this->memberOf($group);
        $this->acting($member);

        OpenPneServer::tool(PostTalkMessageTool::class, ['group_id' => $group->getKey(), 'body' => 'hello from a bot'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('message.body', 'hello from a bot')
                ->where('message.authorId', $member->getKey())
                ->where('message.authorName', $member->name)
                ->where('message.authorIsAi', false)
                ->where('message.hasImages', false)
                ->where('message.imageCount', 0)
                ->where('message.mentions', [])
                ->has('message.id')
                ->has('message.createdAt')
                ->has('message.cursor')
                ->etc());

        $this->assertDatabaseHas('group_messages', [
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'body' => 'hello from a bot',
        ]);
        Event::assertDispatched(GroupMessagePosted::class);
    }

    public function test_a_read_only_token_is_told_which_ability_it_is_missing(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $this->acting($member, [McpAbilities::READ]);

        // Not hidden, unlike a missing room: the caller can act on it, and it discloses nothing
        // about what exists.
        OpenPneServer::tool(PostTalkMessageTool::class, ['group_id' => $group->getKey(), 'body' => 'nope'])
            ->assertHasErrors([McpAbilities::WRITE]);
        OpenPneServer::tool(MarkTalkReadTool::class, ['group_id' => $group->getKey(), 'message_id' => 1])
            ->assertHasErrors([McpAbilities::WRITE]);

        $this->assertDatabaseMissing('group_messages', ['body' => 'nope']);

        // Reading is what the token does carry.
        OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $group->getKey()])->assertOk();
    }

    public function test_posting_to_a_room_the_caller_has_not_joined_is_refused(): void
    {
        // Readable by anyone signed in, writable only by its members — so the refusal comes from the
        // write, and says no more than a missing room would.
        $group = $this->group(TopicReadAccess::Everyone);
        $this->acting(Member::factory()->create());

        OpenPneServer::tool(PostTalkMessageTool::class, ['group_id' => $group->getKey(), 'body' => 'intruding'])
            ->assertHasErrors(['No such talk room']);

        $this->assertDatabaseMissing('group_messages', ['body' => 'intruding']);
    }

    public function test_a_body_of_nothing_is_refused_whatever_it_is_made_of(): void
    {
        $group = $this->group();
        $this->acting($this->memberOf($group));
        // A validation message comes back in the site's language; pinned in every test that asserts
        // on one, so the assertion can name the field it is about.
        $this->app->setLocale('en');

        // The direct tool path meets no HTTP middleware, so each of these reaches the tool exactly
        // as written — which is why the tool holds the blank-body contract itself.
        foreach (['', '   ', "\n\n", "\r\n", " \t "] as $blank) {
            OpenPneServer::tool(PostTalkMessageTool::class, ['group_id' => $group->getKey(), 'body' => $blank])
                ->assertHasErrors(['body']);
        }

        OpenPneServer::tool(PostTalkMessageTool::class, ['group_id' => $group->getKey(), 'body' => 42])
            ->assertHasErrors(['body']);

        $this->assertSame(0, GroupMessage::query()->count());
    }

    public function test_the_cap_counts_code_points_of_the_normalized_body(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $this->acting($member);
        $this->app->setLocale('en');

        // An emoji is one code point, not four bytes, so the cap is exactly reachable with them.
        OpenPneServer::tool(PostTalkMessageTool::class, [
            'group_id' => $group->getKey(),
            'body' => str_repeat('🙂', TalkBody::MAX),
        ])->assertOk();

        OpenPneServer::tool(PostTalkMessageTool::class, [
            'group_id' => $group->getKey(),
            'body' => str_repeat('a', TalkBody::MAX + 1),
        ])->assertHasErrors(['body']);

        // CRLF collapses to LF before the cap is measured: sent as typed this is 7,500 characters,
        // and what is counted — and stored, trailing break trimmed — is 4,999.
        OpenPneServer::tool(PostTalkMessageTool::class, [
            'group_id' => $group->getKey(),
            'body' => str_repeat("a\r\n", intdiv(TalkBody::MAX, 2)),
        ])->assertOk();

        $this->assertSame(2, GroupMessage::query()->count());
        $this->assertSame(
            rtrim(str_repeat("a\n", intdiv(TalkBody::MAX, 2)), "\n"),
            GroupMessage::query()->orderByDesc('id')->value('body'),
        );
    }

    public function test_marking_read_moves_the_cursor_forward_only(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $author = $this->memberOf($group);
        $first = $this->say($group, $author, 'one');
        $second = $this->say($group, $author, 'two');

        $this->acting($member);

        OpenPneServer::tool(MarkTalkReadTool::class, [
            'group_id' => $group->getKey(),
            'message_id' => $second->getKey(),
        ])->assertOk();

        $this->assertSame($second->getKey(), $this->readCursor($group, $member));

        // Replaying an older id is a no-op rather than a rewind.
        OpenPneServer::tool(MarkTalkReadTool::class, [
            'group_id' => $group->getKey(),
            'message_id' => $first->getKey(),
        ])->assertOk();

        $this->assertSame($second->getKey(), $this->readCursor($group, $member));
    }

    public function test_marking_read_refuses_a_message_from_another_room_and_a_reader_with_no_cursor(): void
    {
        $group = $this->group();
        $elsewhere = $this->group();
        $stranger = $this->memberOf($elsewhere);
        $foreign = $this->say($elsewhere, $stranger, 'elsewhere');

        $member = $this->memberOf($group);
        $this->acting($member);

        OpenPneServer::tool(MarkTalkReadTool::class, [
            'group_id' => $group->getKey(),
            'message_id' => $foreign->getKey(),
        ])->assertHasErrors(['No such talk room']);

        // An Everyone room is readable without joining it, and a non-member holds no membership row
        // to carry a cursor on.
        $open = $this->group(TopicReadAccess::Everyone);
        $message = $this->say($open, $this->memberOf($open), 'open');
        $this->acting(Member::factory()->create());

        OpenPneServer::tool(MarkTalkReadTool::class, [
            'group_id' => $open->getKey(),
            'message_id' => $message->getKey(),
        ])->assertHasErrors(['No such talk room']);
    }

    public function test_switching_talk_off_takes_the_tools_away(): void
    {
        $group = $this->group();
        $this->acting($this->memberOf($group));
        $this->setSnsSetting(Feature::GroupTalk->settingKey(), false);

        // Not an error about the room: the tool is not there at all, which is what the room list and
        // the navigation say on every other surface too.
        OpenPneServer::tool(ListTalkRoomsTool::class)->assertHasErrors(['not found']);
        OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $group->getKey()])
            ->assertHasErrors(['not found']);
    }

    public function test_answering_a_message_addresses_its_author_and_notifies_them(): void
    {
        Notification::fake();

        $group = $this->group();
        $bot = $this->memberOf($group);
        $asker = $this->joined($group, 'あかり');
        $question = $this->say($group, $asker, 'what is the weather');

        $this->acting($bot);

        OpenPneServer::tool(PostTalkMessageTool::class, [
            'group_id' => $group->getKey(),
            'body' => 'rain, probably',
            'reply_to_message_id' => $question->getKey(),
        ])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('message.body', '@あかり rain, probably')
                ->where('message.mentions', [$asker->getKey()])
                // Two different questions: which message this answers, and who was spoken to. The
                // reference is what the room draws; the mention is what notifies.
                ->where('message.inReplyTo', ['id' => $question->getKey(), 'authorId' => $asker->getKey()])
                ->etc());

        $posted = GroupMessage::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame($question->getKey(), (int) $posted->in_reply_to_id);
        $mention = DB::table('group_message_mentions')->where('group_message_id', $posted->getKey())->sole();

        $this->assertSame(0, (int) $mention->offset);
        // The separating space is outside the range, so what it covers is exactly the handle — the
        // equality ResolveMentions checks, and the only thing that makes the row survive the write.
        $this->assertSame(1 + mb_strlen($asker->name), (int) $mention->length);
        $this->assertSame('@'.$asker->name, mb_substr($posted->body, (int) $mention->offset, (int) $mention->length));

        Notification::assertSentTo($asker, GroupTalkMentionedNotification::class);
    }

    /** The range the server composed is the range the web surface splits the body on. */
    public function test_the_composed_range_is_the_one_the_web_surface_renders(): void
    {
        $group = $this->group();
        $bot = $this->memberOf($group);
        $asker = $this->joined($group, 'Bob');
        $question = $this->say($group, $asker, 'anyone there');

        $this->acting($bot);
        OpenPneServer::tool(PostTalkMessageTool::class, [
            'group_id' => $group->getKey(),
            'body' => 'here',
            'reply_to_message_id' => $question->getKey(),
        ])->assertOk();

        $posted = GroupMessage::query()->orderByDesc('id')->with('mentions', 'images', 'author')->firstOrFail();
        $serialized = GroupMessageSerializer::message(
            $posted,
            GroupTalkPermissions::for($group, $bot),
            [],
            app(ReplyReferences::class)->of($group, $posted),
        );

        $this->assertSame([['memberId' => $asker->getKey(), 'offset' => 0, 'length' => 1 + mb_strlen($asker->name)]], $serialized['mentions']);
        $this->assertSame(
            '@'.$asker->name,
            mb_substr($serialized['body'], $serialized['mentions'][0]['offset'], $serialized['mentions'][0]['length']),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function unaddressable(): array
    {
        return [
            'their own message' => ['self'],
            'a withdrawn author' => ['withdrawn'],
            'an author who has left the room' => ['left'],
            'an author they have blocked' => ['blocked'],
            'an author who has blocked them' => ['blocker'],
            'a frozen author' => ['frozen'],
        ];
    }

    /**
     * Nobody to address is not a failure to post: the message goes in as written, and the empty
     * `mentions` is what tells the caller no one was named. The reference is written all the same —
     * what this answers does not depend on whether anyone could be spoken to.
     */
    #[DataProvider('unaddressable')]
    public function test_an_answer_with_nobody_to_address_posts_as_a_plain_message(string $situation): void
    {
        Notification::fake();

        $group = $this->group();
        $bot = $this->memberOf($group);
        $asker = $situation === 'self' ? $bot : $this->joined($group, 'Bob');

        $question = $situation === 'withdrawn'
            ? GroupMessage::factory()->withdrawnAuthor()->create(['group_id' => $group->getKey(), 'body' => 'asked'])
            : $this->say($group, $asker, 'asked');

        match ($situation) {
            'left' => DB::table('group_members')
                ->where('group_id', $group->getKey())->where('member_id', $asker->getKey())->delete(),
            'blocked' => DB::table('member_blocks')->insert(['blocker_id' => $bot->getKey(), 'blocked_id' => $asker->getKey()]),
            'blocker' => DB::table('member_blocks')->insert(['blocker_id' => $asker->getKey(), 'blocked_id' => $bot->getKey()]),
            'frozen' => $asker->forceFill(['is_login_rejected' => true])->save(),
            default => null,
        };

        $this->acting($bot);

        OpenPneServer::tool(PostTalkMessageTool::class, [
            'group_id' => $group->getKey(),
            'body' => 'answered',
            'reply_to_message_id' => $question->getKey(),
        ])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('message.body', 'answered')
                ->where('message.mentions', [])
                ->where('message.inReplyTo.id', $question->getKey())
                ->etc());

        $this->assertSame(
            $question->getKey(),
            (int) GroupMessage::query()->where('body', 'answered')->sole()->in_reply_to_id,
        );
        $this->assertSame(0, DB::table('group_message_mentions')->count());
        Notification::assertNothingSent();
    }

    /**
     * What a reading agent decides "is this for me" from. `authorId` is who the answer is owed to, so
     * a parent nobody is behind — deleted, or its author withdrawn — reports null rather than two
     * states that would lead to the same decision.
     */
    public function test_a_read_says_what_each_message_answers(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $asker = $this->memberOf($group);

        $question = $this->say($group, $asker, 'what is the weather');
        $this->answering($group, $member, 'rain, probably', $question);

        $withdrawn = GroupMessage::factory()->withdrawnAuthor()->create(['group_id' => $group->getKey(), 'body' => 'asked']);
        $this->answering($group, $member, 'answering nobody', $withdrawn);

        $retracted = $this->say($group, $asker, 'retracted');
        $this->answering($group, $member, 'answering a ghost', $retracted);
        $retractedId = $retracted->getKey();
        $retracted->delete();

        $this->acting($member);

        OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $group->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('messages.0.inReplyTo', null)
                ->where('messages.1.inReplyTo', ['id' => $question->getKey(), 'authorId' => $asker->getKey()])
                ->where('messages.3.inReplyTo', ['id' => $withdrawn->getKey(), 'authorId' => null])
                ->where('messages.4.inReplyTo', ['id' => $retractedId, 'authorId' => null])
                ->etc());
    }

    /** Being answered is being spoken to, so the room says it is waiting on the caller. */
    public function test_the_room_list_counts_an_answer_to_something_the_caller_said(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $other = $this->memberOf($group);

        $mine = $this->say($group, $viewer, 'what is the weather');
        $this->answering($group, $other, 'rain, probably', $mine);
        $this->say($group, $other, 'unrelated chatter');

        $this->acting($viewer);

        OpenPneServer::tool(ListTalkRoomsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('rooms.0.unread', 2)
                ->where('rooms.0.unreadMentions', 1)
                ->etc());
    }

    /** One message in the room answering another, as the composer and the reply tool both write one. */
    private function answering(Group $group, Member $author, string $body, GroupMessage $parent): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'body' => $body,
            'in_reply_to_id' => $parent->getKey(),
        ]);
    }

    public function test_answering_a_message_this_room_does_not_hold_is_refused_without_saying_it_exists(): void
    {
        $group = $this->group();
        $elsewhere = $this->group();
        $bot = $this->memberOf($group);
        $foreign = $this->say($elsewhere, $this->memberOf($elsewhere), 'elsewhere');

        $this->acting($bot);

        // Another room's message and an id that names nothing at all answer exactly the same.
        foreach ([$foreign->getKey(), $foreign->getKey() + 9999] as $id) {
            OpenPneServer::tool(PostTalkMessageTool::class, [
                'group_id' => $group->getKey(),
                'body' => 'answered',
                'reply_to_message_id' => $id,
            ])->assertHasErrors(['No such talk room']);
        }

        $this->assertSame(0, GroupMessage::query()->where('group_id', $group->getKey())->count());
    }

    /**
     * The cap is measured again after the handle is prefixed, because the handle is the server's
     * addition and nothing downstream re-checks the body.
     */
    public function test_a_reply_the_handle_no_longer_leaves_room_for_is_refused(): void
    {
        $group = $this->group();
        $bot = $this->memberOf($group);
        $asker = $this->joined($group, str_repeat('な', 20));
        $question = $this->say($group, $asker, 'asked');

        $this->acting($bot);
        $this->app->setLocale('en');

        $handle = 1 + mb_strlen($asker->name) + 1; // "@name " — the space is prefixed too

        OpenPneServer::tool(PostTalkMessageTool::class, [
            'group_id' => $group->getKey(),
            'body' => str_repeat('a', TalkBody::MAX - $handle),
            'reply_to_message_id' => $question->getKey(),
        ])->assertOk();

        OpenPneServer::tool(PostTalkMessageTool::class, [
            'group_id' => $group->getKey(),
            'body' => str_repeat('a', TalkBody::MAX - $handle + 1),
            'reply_to_message_id' => $question->getKey(),
        ])->assertHasErrors(['body']);

        $this->assertSame(TalkBody::MAX, mb_strlen((string) GroupMessage::query()->orderByDesc('id')->value('body')));
        $this->assertSame(2, GroupMessage::query()->where('group_id', $group->getKey())->count());
    }

    /**
     * Nothing changed between composing the handle and resolving it, so nothing lands here: the
     * write goes in on the first attempt, and the room is left holding one message.
     */
    public function test_a_block_landing_between_the_handle_and_the_write_posts_the_answer_plain(): void
    {
        Notification::fake();

        $group = $this->group();
        $bot = $this->memberOf($group);
        $asker = $this->joined($group, 'Bob');
        $question = $this->say($group, $asker, 'what is the weather');

        $this->raceBeforeTheWrite(fn () => DB::table('member_blocks')->insert([
            'blocker_id' => $asker->getKey(),
            'blocked_id' => $bot->getKey(),
        ]));

        $this->acting($bot);

        OpenPneServer::tool(PostTalkMessageTool::class, [
            'group_id' => $group->getKey(),
            'body' => 'rain, probably',
            'reply_to_message_id' => $question->getKey(),
        ])
            ->assertOk()
            // The handle the first attempt composed went back with it: what is stored is the text as
            // the caller wrote it.
            ->assertStructuredContent(fn ($json) => $json
                ->where('message.body', 'rain, probably')
                ->where('message.mentions', [])
                ->etc());

        $this->assertSame(1, GroupMessage::query()->where('member_id', $bot->getKey())->count());
        $this->assertSame(0, DB::table('group_message_mentions')->count());
        Notification::assertNothingSent();
    }

    /** A rename is the one race worth composing again for: there is still someone to address. */
    public function test_a_rename_between_the_handle_and_the_write_is_composed_again(): void
    {
        Notification::fake();

        $group = $this->group();
        $bot = $this->memberOf($group);
        $asker = $this->joined($group, 'Bob');
        $question = $this->say($group, $asker, 'what is the weather');

        $this->raceBeforeTheWrite(fn () => DB::table('members')
            ->where('id', $asker->getKey())
            ->update(['name' => 'Robert']));

        $this->acting($bot);

        OpenPneServer::tool(PostTalkMessageTool::class, [
            'group_id' => $group->getKey(),
            'body' => 'rain, probably',
            'reply_to_message_id' => $question->getKey(),
        ])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('message.body', '@Robert rain, probably')
                ->where('message.mentions', [$asker->getKey()])
                ->etc());

        $posted = GroupMessage::query()->where('member_id', $bot->getKey())->sole();
        $mention = DB::table('group_message_mentions')->where('group_message_id', $posted->getKey())->sole();

        $this->assertSame(0, (int) $mention->offset);
        $this->assertSame(1 + mb_strlen('Robert'), (int) $mention->length);
        Notification::assertSentTo($asker, GroupTalkMentionedNotification::class);
    }

    /** What the addressed member sees for it: the answer waiting, counted as one that names them. */
    public function test_an_answer_reaches_the_addressed_members_unread_mention_count(): void
    {
        $group = $this->group();
        $bot = $this->memberOf($group);
        $asker = $this->joined($group, 'Bob');
        $question = $this->say($group, $asker, 'what is the weather');

        $this->acting($bot);
        $answerId = null;
        OpenPneServer::tool(PostTalkMessageTool::class, [
            'group_id' => $group->getKey(),
            'body' => 'rain, probably',
            'reply_to_message_id' => $question->getKey(),
        ])->assertOk()->assertStructuredContent(function ($json) use (&$answerId): void {
            $answerId = $json->toArray()['message']['id'];
            $json->etc();
        });

        $this->acting($asker);

        OpenPneServer::tool(ListTalkRoomsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('rooms.0.unreadMentions', 1)->etc());

        OpenPneServer::tool(MarkTalkReadTool::class, [
            'group_id' => $group->getKey(),
            'message_id' => $answerId,
        ])->assertOk();

        OpenPneServer::tool(ListTalkRoomsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('rooms.0.unread', 0)
                ->where('rooms.0.unreadMentions', 0)
                ->etc());
    }

    /** A caller racing every attempt is answered as one with nobody to address: posted, unaddressed. */
    public function test_a_rename_on_every_attempt_gives_up_and_posts_plain(): void
    {
        Notification::fake();

        $group = $this->group();
        $bot = $this->memberOf($group);
        $asker = $this->joined($group, 'Bob');
        $question = $this->say($group, $asker, 'what is the weather');

        $names = ['Robert', 'Bobby'];
        $this->raceBeforeTheWrite(function () use ($asker, &$names): void {
            DB::table('members')->where('id', $asker->getKey())->update(['name' => array_shift($names)]);
        }, times: 2);

        $this->acting($bot);

        OpenPneServer::tool(PostTalkMessageTool::class, [
            'group_id' => $group->getKey(),
            'body' => 'rain, probably',
            'reply_to_message_id' => $question->getKey(),
        ])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('message.body', 'rain, probably')
                ->where('message.mentions', [])
                ->etc());

        $this->assertSame(1, GroupMessage::query()->where('member_id', $bot->getKey())->count());
        $this->assertSame(0, DB::table('group_message_mentions')->count());
        Notification::assertNothingSent();
    }

    /**
     * Move the world in the window the tool cannot hold shut: after it composed the handle and before
     * the write resolves it. Only the first $times attempts are raced, so the one after them sees the
     * state the race left behind.
     */
    private function raceBeforeTheWrite(Closure $race, int $times = 1): void
    {
        $this->app->singleton(CreateGroupMessage::class, fn () => new class(app(PostImages::class), app(ResolveMentions::class), app(GroupTalkRoomNotificationRows::class), $race, $times) extends CreateGroupMessage
        {
            public function __construct(PostImages $images, ResolveMentions $mentions, GroupTalkRoomNotificationRows $rows, private readonly Closure $race, private int $times)
            {
                parent::__construct($images, $mentions, $rows);
            }

            public function __invoke(Member $author, Group $group, string $body, array $mentions = [], array $images = [], bool $mentionsRequired = false, ?GroupMessage $inReplyTo = null): GroupMessage
            {
                if ($this->times-- > 0) {
                    ($this->race)();
                }

                return parent::__invoke($author, $group, $body, $mentions, $images, $mentionsRequired, $inReplyTo);
            }
        });
    }

    public function test_the_room_list_says_how_many_of_the_unread_name_the_caller(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $other = $this->memberOf($group);
        $bystander = $this->memberOf($group);

        $this->names($this->say($group, $other, 'hey'), $viewer);
        // Named twice in one line: one message waiting, not two.
        $twice = $this->say($group, $other, 'hey again');
        $this->names($twice, $viewer);
        $this->names($twice, $viewer, offset: 20);
        $addressedToSomeoneElse = $this->say($group, $other, 'not you');
        $this->names($addressedToSomeoneElse, $bystander);

        $this->acting($viewer);

        OpenPneServer::tool(ListTalkRoomsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('rooms.0.unread', 3)
                ->where('rooms.0.unreadMentions', 2)
                ->etc());

        OpenPneServer::tool(MarkTalkReadTool::class, [
            'group_id' => $group->getKey(),
            'message_id' => $addressedToSomeoneElse->getKey(),
        ])->assertOk();

        // Read is read: the mention count is the same unread, narrowed, so the cursor clears both.
        OpenPneServer::tool(ListTalkRoomsTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('rooms.0.unread', 0)
                ->where('rooms.0.unreadMentions', 0)
                ->etc());
    }

    /** A member of the room under a name of their own, since a composed handle is that name. */
    private function joined(Group $group, string $name): Member
    {
        $member = Member::factory()->create(['name' => $name]);
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        return $member;
    }

    /** A mention row naming $member in $message, as the web surface's picker writes one. */
    private function names(GroupMessage $message, Member $member, int $offset = 0): void
    {
        DB::table('group_message_mentions')->insert([
            'group_message_id' => $message->getKey(),
            'member_id' => $member->getKey(),
            'offset' => $offset,
            'length' => 1 + mb_strlen($member->name),
        ]);
    }

    private function readCursor(Group $group, Member $member): ?int
    {
        $value = DB::table('group_members')
            ->where('group_id', $group->getKey())
            ->where('member_id', $member->getKey())
            ->value('talk_read_message_id');

        return $value === null ? null : (int) $value;
    }
}
