<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Models\DirectMessage;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tests\TestCase;

/**
 * Pins the two directions of the dual alias a renamed model carries: which alias new rows are
 * written with, and which older ones still resolve. Both are read off `files.related_entity_type`,
 * so a silent flip would make old attachments unresolvable (FilePolicy denies an unknown alias) or
 * write a legacy alias into new rows.
 */
class MorphAliasTest extends TestCase
{
    public function test_a_direct_message_writes_the_direct_message_alias(): void
    {
        $this->assertSame('directMessage', (new DirectMessage)->getMorphClass());
    }

    public function test_the_pre_rename_message_alias_still_resolves(): void
    {
        $this->assertSame(DirectMessage::class, Relation::getMorphedModel('message'));
    }

    public function test_a_group_writes_the_group_alias(): void
    {
        $this->assertSame('group', (new Group)->getMorphClass());
    }

    public function test_the_pre_rename_community_alias_still_resolves(): void
    {
        $this->assertSame(Group::class, Relation::getMorphedModel('community'));
    }

    public function test_a_group_topic_and_its_comment_write_the_group_topic_aliases(): void
    {
        $this->assertSame('groupTopic', (new GroupTopic)->getMorphClass());
        $this->assertSame('groupTopicComment', (new GroupTopicComment)->getMorphClass());
    }

    public function test_the_pre_rename_community_topic_aliases_still_resolve(): void
    {
        $this->assertSame(GroupTopic::class, Relation::getMorphedModel('communityTopic'));
        $this->assertSame(GroupTopicComment::class, Relation::getMorphedModel('communityTopicComment'));
    }

    public function test_a_group_event_and_its_comment_write_the_group_event_aliases(): void
    {
        $this->assertSame('groupEvent', (new GroupEvent)->getMorphClass());
        $this->assertSame('groupEventComment', (new GroupEventComment)->getMorphClass());
    }

    public function test_the_pre_rename_community_event_aliases_still_resolve(): void
    {
        $this->assertSame(GroupEvent::class, Relation::getMorphedModel('communityEvent'));
        $this->assertSame(GroupEventComment::class, Relation::getMorphedModel('communityEventComment'));
    }
}
