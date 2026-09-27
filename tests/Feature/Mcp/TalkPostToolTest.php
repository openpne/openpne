<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Features\GroupTalk\Events\GroupMessagePosted;
use App\Features\GroupTalk\TalkBody;
use App\Features\GroupTopic\TopicReadAccess;
use App\Mcp\McpAbilities;
use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\ListTalkRoomsTool;
use App\Mcp\Tools\MarkTalkReadTool;
use App\Mcp\Tools\PostTalkMessageTool;
use App\Mcp\Tools\ReadTalkMessagesTool;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Support\Facades\Event;

class TalkPostToolTest extends TalkToolsTestCase
{
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
        // Readable by anyone signed in but writable only by its members, so the refusal comes from
        // the write.
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

        // The tool path meets no HTTP middleware, so each of these reaches the tool exactly as
        // written.
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

    public function test_switching_talk_off_takes_the_tools_away(): void
    {
        $group = $this->group();
        $this->acting($this->memberOf($group));
        $this->setSnsSetting(Feature::GroupTalk->settingKey(), false);

        // Not an error about the room: the tool is not there at all.
        OpenPneServer::tool(ListTalkRoomsTool::class)->assertHasErrors(['not found']);
        OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $group->getKey()])
            ->assertHasErrors(['not found']);
    }
}
