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
 * Every unit here is on by default and set explicitly anyway, so a suite reads as independent of
 * the install default and switching one off inside a test is visibly the exception.
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

    protected function say(Group $group, Member $author, string $body): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'body' => $body,
        ]);
    }

    protected function token(Member $member, array $abilities = [McpAbilities::READ, McpAbilities::WRITE]): string
    {
        return $member->createToken(McpAbilities::TOKEN_NAME, $abilities)->plainTextToken;
    }
}
