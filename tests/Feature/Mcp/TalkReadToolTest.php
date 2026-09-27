<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Features\GroupTalk\Queries\GroupTalkMessages;
use App\Features\GroupTopic\TopicReadAccess;
use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\ReadTalkMessagesTool;
use App\Models\File;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class TalkReadToolTest extends TalkToolsTestCase
{
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
}
