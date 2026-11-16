<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Reactions\ReactionVocabulary;
use App\Mcp\McpAbilities;
use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\AddTalkReactionTool;
use App\Mcp\Tools\ReadTalkMessagesTool;
use App\Mcp\Tools\RemoveTalkReactionTool;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class TalkReactionToolTest extends TalkToolsTestCase
{
    public function test_adding_puts_the_emoji_on_the_message_and_answers_with_its_chip_row(): void
    {
        $group = $this->group();
        $owner = $this->memberOf($group);
        $agent = $this->memberOf($group);
        $message = $this->say($group, $agent, 'done: deployed');

        $this->acting($owner);

        OpenPneServer::tool(AddTalkReactionTool::class, [
            'group_id' => $group->getKey(),
            'message_id' => $message->getKey(),
            'emoji' => ReactionVocabulary::LIKE,
        ])->assertOk()->assertStructuredContent([
            'messageId' => $message->getKey(),
            'reactions' => [['emoji' => ReactionVocabulary::LIKE, 'count' => 1, 'mine' => true]],
        ]);

        $this->assertDatabaseHas('reactions', [
            'reactable_type' => $message->getMorphClass(),
            'reactable_id' => $message->getKey(),
            'member_id' => $owner->getKey(),
            'emoji' => ReactionVocabulary::LIKE,
        ]);

        // Adding what is already held changes nothing — a retried call settles where it pointed.
        OpenPneServer::tool(AddTalkReactionTool::class, [
            'group_id' => $group->getKey(),
            'message_id' => $message->getKey(),
            'emoji' => ReactionVocabulary::LIKE,
        ])->assertOk();

        $this->assertDatabaseCount('reactions', 1);
    }

    public function test_reading_a_page_reports_each_messages_reactions_from_the_callers_side(): void
    {
        $group = $this->group();
        $owner = $this->memberOf($group);
        $agent = $this->memberOf($group);
        $reacted = $this->say($group, $agent, 'reacted to');
        $this->say($group, $agent, 'left alone');

        $this->acting($owner);
        OpenPneServer::tool(AddTalkReactionTool::class, [
            'group_id' => $group->getKey(), 'message_id' => $reacted->getKey(), 'emoji' => "\u{2705}",
        ])->assertOk();

        // The agent reads its owner's acknowledgement: held by one member, and not by the agent.
        $this->acting($agent);

        OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $group->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('messages.0.reactions', [['emoji' => "\u{2705}", 'count' => 1, 'mine' => false]])
                ->where('messages.1.reactions', [])
                ->etc());

        $this->acting($owner);

        OpenPneServer::tool(ReadTalkMessagesTool::class, ['group_id' => $group->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('messages.0.reactions', [['emoji' => "\u{2705}", 'count' => 1, 'mine' => true]])
                ->etc());
    }

    public function test_removing_takes_only_the_callers_own_emoji_off(): void
    {
        $group = $this->group();
        $owner = $this->memberOf($group);
        $other = $this->memberOf($group);
        $message = $this->say($group, $other, 'both reacted');

        foreach ([$owner, $other] as $member) {
            $this->acting($member);
            OpenPneServer::tool(AddTalkReactionTool::class, [
                'group_id' => $group->getKey(), 'message_id' => $message->getKey(), 'emoji' => ReactionVocabulary::LIKE,
            ])->assertOk();
        }

        $this->acting($owner);

        OpenPneServer::tool(RemoveTalkReactionTool::class, [
            'group_id' => $group->getKey(),
            'message_id' => $message->getKey(),
            'emoji' => ReactionVocabulary::LIKE,
        ])->assertOk()->assertStructuredContent([
            'messageId' => $message->getKey(),
            'reactions' => [['emoji' => ReactionVocabulary::LIKE, 'count' => 1, 'mine' => false]],
        ]);

        $this->assertDatabaseHas('reactions', ['member_id' => $other->getKey()]);
        $this->assertDatabaseMissing('reactions', ['member_id' => $owner->getKey()]);

        // Removing what is not held changes nothing.
        OpenPneServer::tool(RemoveTalkReactionTool::class, [
            'group_id' => $group->getKey(), 'message_id' => $message->getKey(), 'emoji' => ReactionVocabulary::LIKE,
        ])->assertOk();

        $this->assertDatabaseCount('reactions', 1);
    }

    public function test_an_emoji_the_site_withdrew_can_still_be_taken_back(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = $this->say($group, $member, 'reacted before the set changed');
        DB::table('reactions')->insert([
            'reactable_type' => $message->getMorphClass(),
            'reactable_id' => $message->getKey(),
            'member_id' => $member->getKey(),
            'emoji' => "\u{1F984}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->acting($member);

        OpenPneServer::tool(RemoveTalkReactionTool::class, [
            'group_id' => $group->getKey(), 'message_id' => $message->getKey(), 'emoji' => "\u{1F984}",
        ])->assertOk()->assertStructuredContent(['messageId' => $message->getKey(), 'reactions' => []]);

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_an_emoji_outside_the_sites_set_cannot_be_added(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = $this->say($group, $member, 'hello');
        $this->acting($member);
        $this->app->setLocale('en');

        OpenPneServer::tool(AddTalkReactionTool::class, [
            'group_id' => $group->getKey(), 'message_id' => $message->getKey(), 'emoji' => "\u{1F984}",
        ])->assertHasErrors(['emoji']);

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_read_only_token_is_told_which_ability_it_is_missing(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $message = $this->say($group, $member, 'hello');
        $this->acting($member, [McpAbilities::READ]);

        foreach ([AddTalkReactionTool::class, RemoveTalkReactionTool::class] as $tool) {
            OpenPneServer::tool($tool, [
                'group_id' => $group->getKey(), 'message_id' => $message->getKey(), 'emoji' => ReactionVocabulary::LIKE,
            ])->assertHasErrors([McpAbilities::WRITE]);
        }

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_reacting_where_the_caller_may_only_read_or_to_another_rooms_message_is_refused_alike(): void
    {
        // Readable by anyone signed in but writable only by its members, so the refusal comes from
        // the write gate rather than the read one.
        $open = $this->group(TopicReadAccess::Everyone);
        $message = $this->say($open, $this->memberOf($open), 'open room');

        $this->acting(Member::factory()->create());

        OpenPneServer::tool(AddTalkReactionTool::class, [
            'group_id' => $open->getKey(), 'message_id' => $message->getKey(), 'emoji' => ReactionVocabulary::LIKE,
        ])->assertHasErrors(['No such talk room']);

        // An emoji outside the set is not what the refusal reports: the gate answers first.
        OpenPneServer::tool(AddTalkReactionTool::class, [
            'group_id' => $open->getKey(), 'message_id' => $message->getKey(), 'emoji' => "\u{1F984}",
        ])->assertHasErrors(['No such talk room']);

        // A member of one room naming a message of another answers the same way.
        $mine = $this->group();
        $member = $this->memberOf($mine);
        $this->acting($member);

        OpenPneServer::tool(AddTalkReactionTool::class, [
            'group_id' => $mine->getKey(), 'message_id' => $message->getKey(), 'emoji' => ReactionVocabulary::LIKE,
        ])->assertHasErrors(['No such talk room']);

        $this->assertDatabaseCount('reactions', 0);
    }
}
