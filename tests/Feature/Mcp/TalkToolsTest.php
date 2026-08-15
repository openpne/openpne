<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Features\GroupTalk\Events\GroupMessagePosted;
use App\Features\GroupTalk\Queries\GroupTalkMessages;
use App\Features\GroupTalk\TalkBody;
use App\Features\GroupTopic\TopicReadAccess;
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
use App\Support\Feature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

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

    private function readCursor(Group $group, Member $member): ?int
    {
        $value = DB::table('group_members')
            ->where('group_id', $group->getKey())
            ->where('member_id', $member->getKey())
            ->value('talk_read_message_id');

        return $value === null ? null : (int) $value;
    }
}
