<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Features\GroupTopic\TopicReadAccess;
use App\Mcp\McpAbilities;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared setup for the MCP suites: the endpoint's own unit, and the units its tools reach into. All
 * are on by default; they are set explicitly so a suite reads as independent of whatever an install
 * default happens to be, and so switching one off inside a test is visibly the exception.
 */
abstract class McpTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::FeatureMcpEnabled, true);
        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, true);
        $this->setSnsSetting(SnsSettingKey::FeatureDiaryEnabled, true);
    }

    protected function group(TopicReadAccess $read = TopicReadAccess::Everyone): Group
    {
        return Group::factory()->create(['topic_read_access' => $read]);
    }

    protected function memberOf(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        return $member;
    }

    /** One message in the room, written by $author. */
    protected function say(Group $group, Member $author, string $body): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'body' => $body,
        ]);
    }

    /** The plain-text token an MCP client would present. */
    protected function token(Member $member, array $abilities = [McpAbilities::READ, McpAbilities::WRITE]): string
    {
        return $member->createToken(McpAbilities::TOKEN_NAME, $abilities)->plainTextToken;
    }
}
