<?php

namespace Tests\Feature\GroupEvent\Classic;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenPNE 3's comment list offered a "Reply" link per comment only while the administrator switched
 * it on; the link quotes ">>N name" into the comment box through a script and jumps to the form
 * without one.
 */
class GroupEventCommentReplyLinkTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => GroupRole::Member,
        ]);

        return $member;
    }

    /** @return array{0: GroupEvent, 1: Member, 2: Member} the record, a member who may comment, the comment's author */
    private function threadWithOneComment(): array
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $author = $this->joined($group);
        $record = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
        GroupEventComment::factory()->create(['group_event_id' => $record->getKey(), 'member_id' => $author->getKey(), 'number' => 1]);

        return [$record, $member, $author];
    }

    public function test_no_reply_link_while_the_switch_is_off(): void
    {
        [$record, $member] = $this->threadWithOneComment();

        $response = $this->actingAs($member)->get(route('group.events.show', $record));

        $response->assertOk();
        $response->assertDontSee('class="reply"', false);
        $response->assertDontSee('classic-comment-reply.js', false);
    }

    public function test_the_switch_adds_a_reply_link_that_names_the_comment_and_ships_the_script(): void
    {
        $this->setSnsSetting(SnsSettingKey::GroupEventCommentReply, true);
        [$record, $member, $author] = $this->threadWithOneComment();

        $response = $this->actingAs($member)->get(route('group.events.show', $record));

        $response->assertOk();
        $response->assertSee('<a class="reply" href="#communityEvent_comment_form" data-comment-reply="#comment_body" data-number="1" data-name="'.e($author->name).'">Reply</a>', false);
        $response->assertSee('js/classic-comment-reply.js', false);
    }

    public function test_a_viewer_who_cannot_comment_gets_no_reply_link(): void
    {
        $this->setSnsSetting(SnsSettingKey::GroupEventCommentReply, true);
        [$record] = $this->threadWithOneComment();
        $stranger = Member::factory()->create();

        $response = $this->actingAs($stranger)->get(route('group.events.show', $record));

        $response->assertOk();
        $response->assertDontSee('class="reply"', false);
    }
}
