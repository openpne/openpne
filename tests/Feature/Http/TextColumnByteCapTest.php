<?php

namespace Tests\Feature\Http;

use App\Features\DirectMessage\Actions\SendDirectMessage;
use App\Features\DirectMessage\DirectMessageComposeData;
use App\Features\Group\GroupRole;
use App\Models\Diary;
use App\Models\DirectMessage;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TextColumnByteCapTest extends TestCase
{
    use RefreshDatabase;

    private const OVER = 65536;

    public function test_comments_over_the_text_column_are_rejected(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $this->actingAs($owner)->postJson(route('diary.comment.store', $diary), ['body' => $this->over()])
            ->assertStatus(422)->assertJsonValidationErrors('body');

        $group = Group::factory()->create();
        $member = $this->joined($group, GroupRole::Admin);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
        $event = GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);

        $this->actingAs($member)->postJson(route('group.topics.comment.store', $topic), ['body' => $this->over()])
            ->assertStatus(422)->assertJsonValidationErrors('body');
        $this->actingAs($member)->postJson(route('group.events.comment.store', $event), ['body' => $this->over()])
            ->assertStatus(422)->assertJsonValidationErrors('body');

        $this->assertSame(0, DB::table('diary_comments')->count() + DB::table('group_topic_comments')->count() + DB::table('group_event_comments')->count());
    }

    public function test_titles_and_event_details_over_the_text_column_are_rejected(): void
    {
        $owner = Member::factory()->create();
        $this->actingAs($owner)->postJson('/diary/create', ['title' => $this->over(), 'body' => 'b', 'visibility' => '1'])
            ->assertStatus(422)->assertJsonValidationErrors('title');

        $group = Group::factory()->create();
        $member = $this->joined($group, GroupRole::Admin);
        $this->actingAs($member)->postJson(route('group.topics.store', $group), ['name' => $this->over(), 'body' => 'b'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->actingAs($member)->postJson(route('group.events.store', $group), [
            'name' => $this->over(), 'body' => 'b', 'open_date' => now()->addWeek()->format('Y-m-d'),
            'area' => $this->over(), 'open_date_comment' => $this->over(),
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'area', 'open_date_comment']);
    }

    /** The cap counts bytes: 21,846 three-byte characters pass a character count but not the column. */
    public function test_a_direct_message_over_the_text_column_is_rejected_on_send_and_on_draft_edit(): void
    {
        [$sender, $recipient] = Member::factory()->count(2)->create();
        $multibyte = str_repeat('あ', 21846);

        $this->actingAs($sender)->postJson(route('message.compose.store'), [
            'to' => $recipient->getKey(), 'subject' => $multibyte, 'body' => $this->over(), 'action' => 'send',
        ])->assertStatus(422)->assertJsonValidationErrors(['subject', 'body']);

        $draft = $this->draftTo($sender, $recipient);
        $this->actingAs($sender)->postJson(route('message.draft.update', $draft), [
            'subject' => $this->over(), 'body' => $multibyte, 'action' => 'draft',
        ])->assertStatus(422)->assertJsonValidationErrors(['subject', 'body']);

        $this->assertSame('Draft', $draft->fresh()->subject);
    }

    public function test_a_group_description_over_the_text_column_is_rejected(): void
    {
        $group = Group::factory()->create();
        $admin = $this->joined($group, GroupRole::Admin);

        $this->actingAs($admin)->postJson('/groups/edit?'.http_build_query(['id' => $group->getKey()]), $this->groupPayload($group, $this->over()))
            ->assertStatus(422)->assertJsonValidationErrors('description');
    }

    public function test_a_profile_text_value_over_the_text_column_is_rejected(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'textarea']);

        $this->actingAs($member)->postJson('/member/edit/profile', $this->profilePayload($member, $profile, $this->over()))
            ->assertStatus(422)->assertJsonValidationErrors('profile.'.$profile->getKey());

        $this->assertDatabaseMissing('member_profiles', ['member_id' => $member->getKey(), 'profile_id' => $profile->getKey()]);
    }

    /** Only SQLite stores such a row; a MySQL or MariaDB TEXT column refuses it at insert. */
    public function test_an_existing_value_over_the_cap_still_shows_and_must_be_shortened_to_save(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('A TEXT column on this engine cannot hold the row.');
        }

        $group = Group::factory()->create(['description' => $this->over()]);
        $admin = $this->joined($group, GroupRole::Admin);
        $profile = Profile::factory()->create(['form_type' => 'textarea']);
        MemberProfile::factory()->create(['member_id' => $admin->getKey(), 'profile_id' => $profile->getKey(), 'value' => $this->over()]);
        $recipient = Member::factory()->create();
        $draft = $this->draftTo($admin, $recipient);
        $draft->forceFill(['body' => $this->over()])->save();

        $this->actingAs($admin)->get(route('group.show', $group))->assertOk();
        $this->actingAs($admin)->get(route('member.profile.edit'))->assertOk();
        $this->actingAs($admin)->get(route('message.draft.edit', $draft))->assertOk();

        $this->actingAs($admin)->postJson('/groups/edit?'.http_build_query(['id' => $group->getKey()]), $this->groupPayload($group, $group->description))
            ->assertStatus(422)->assertJsonValidationErrors('description');
        $this->actingAs($admin)->postJson('/member/edit/profile', $this->profilePayload($admin, $profile, $this->over()))
            ->assertStatus(422)->assertJsonValidationErrors('profile.'.$profile->getKey());
        $this->actingAs($admin)->postJson(route('message.draft.update', $draft), ['subject' => 'Draft', 'body' => $this->over(), 'action' => 'draft'])
            ->assertStatus(422)->assertJsonValidationErrors('body');

        $this->assertSame(self::OVER, strlen((string) $group->fresh()->description));

        $this->actingAs($admin)->post('/groups/edit?'.http_build_query(['id' => $group->getKey()]), $this->groupPayload($group, 'shortened'))
            ->assertRedirect(route('group.show', $group));
        $this->assertSame('shortened', $group->fresh()->description);
    }

    private function over(): string
    {
        return str_repeat('a', self::OVER);
    }

    private function joined(Group $group, GroupRole $role): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => $role]);

        return $member;
    }

    private function draftTo(Member $sender, Member $recipient): DirectMessage
    {
        return app(SendDirectMessage::class)($sender, new DirectMessageComposeData($recipient->getKey(), 'Draft', 'Body'), asDraft: true);
    }

    /** @return array<string, mixed> */
    private function groupPayload(Group $group, ?string $description): array
    {
        return [
            'name' => $group->name,
            'description' => $description,
            'register_policy' => $group->register_policy->slug(),
            'topic_read_access' => $group->topic_read_access->slug(),
            'topic_post_authority' => $group->topic_post_authority->slug(),
        ];
    }

    /** @return array<string, mixed> */
    private function profilePayload(Member $member, Profile $profile, string $value): array
    {
        return [
            'name' => $member->name,
            'profile' => [$profile->getKey() => $value],
            'visibility' => [$profile->getKey() => (string) Visibility::Members->value],
        ];
    }
}
