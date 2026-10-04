<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Features\GroupTalk\Actions\CreateGroupMessage;
use App\Features\GroupTalk\GroupTalkPermissions;
use App\Features\GroupTalk\GroupTalkRoomNotificationRows;
use App\Features\GroupTalk\Queries\ReplyReferences;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Features\GroupTalk\TalkBody;
use App\Features\Timeline\Actions\ResolveMentions;
use App\Files\PostImages;
use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\ListTalkRoomsTool;
use App\Mcp\Tools\MarkTalkReadTool;
use App\Mcp\Tools\PostTalkMessageTool;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Notifications\GroupTalk\GroupTalkMentionedNotification;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;

class TalkAnswerToolTest extends TalkToolsTestCase
{
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
                ->where('message.inReplyTo', ['id' => $question->getKey(), 'authorId' => $asker->getKey()])
                ->etc());

        $posted = GroupMessage::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame($question->getKey(), (int) $posted->in_reply_to_id);
        $mention = DB::table('group_message_mentions')->where('group_message_id', $posted->getKey())->sole();

        $this->assertSame(0, (int) $mention->offset);
        // The separating space is outside the range, so what it covers is exactly the handle.
        $this->assertSame(1 + mb_strlen($asker->name), (int) $mention->length);
        $this->assertSame('@'.$asker->name, mb_substr($posted->body, (int) $mention->offset, (int) $mention->length));

        Notification::assertSentTo($asker, GroupTalkMentionedNotification::class);
    }

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
            'blocked' => DB::table('member_blocks')->insert(['blocker_id' => $bot->getKey(), 'blocked_id' => $asker->getKey(), 'created_at' => now()]),
            'blocker' => DB::table('member_blocks')->insert(['blocker_id' => $asker->getKey(), 'blocked_id' => $bot->getKey(), 'created_at' => now()]),
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
            'created_at' => now(),
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
}
