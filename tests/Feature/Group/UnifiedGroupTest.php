<?php

namespace Tests\Feature\Group;

use App\Features\GroupTopic\TopicReadAccess;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupCategory;
use App\Models\GroupEvent;
use App\Models\GroupEventImage;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
use App\Models\GroupTopic;
use App\Models\GroupTopicImage;
use App\Models\Member;
use App\Support\Look;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The look on the group top. It pins both sides — standard, the shipped page is what it was;
 * unified, the new page carries every entrance the old one offered under the same conditions — and
 * the two things the layout adds: the groups filed beside this one, which must read the same to
 * everybody, and the picture strip, whose every file is asked again.
 */
class UnifiedGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
    }

    private function unifiedOn(): void
    {
        $this->setSnsSetting(SnsSettingKey::DefaultLook, Look::Unified);
        $this->freshRequestState();
    }

    private function join(Group $group, Member $member, string $role = 'member'): GroupMember
    {
        return GroupMember::factory()->{$role}()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    private function apply(Group $group, Member $member): void
    {
        DB::table('group_join_requests')->insert([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    private function message(Group $group, string $at, Member $author): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);
    }

    private function topic(Group $group, string $at, Member $author): GroupTopic
    {
        return GroupTopic::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);
    }

    private function event(Group $group, string $at, Member $author): GroupEvent
    {
        return GroupEvent::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'created_at' => Carbon::parse($at),
            'updated_at' => Carbon::parse($at),
        ]);
    }

    /**
     * A picture on a parent, with its File linked to that parent the way an upload links it — which
     * is what FilePolicy resolves the viewer's clearance through. `$linked` false leaves the file
     * unowned, the state the policy refuses.
     */
    private function picture(GroupMessage|GroupTopic|GroupEvent $parent, int $number = 1, bool $linked = true): File
    {
        [$class, $alias, $key] = match (true) {
            $parent instanceof GroupMessage => [GroupMessageImage::class, 'groupMessage', 'group_message_id'],
            $parent instanceof GroupTopic => [GroupTopicImage::class, 'groupTopic', 'post_id'],
            $parent instanceof GroupEvent => [GroupEventImage::class, 'groupEvent', 'post_id'],
        };

        $file = File::factory()->create($linked ? [
            'related_entity_type' => $alias,
            'related_entity_id' => $parent->getKey(),
        ] : []);

        $class::factory()->create([$key => $parent->getKey(), 'file_id' => $file->getKey(), 'number' => $number]);

        return $file;
    }

    /**
     * Queries whose SQL carries every one of $needles. The fingerprints below pick one query out of a
     * page: `comments_count` / `participants_count` are the recent-board reads, `images_exists` is the
     * talk preview, and `limit 9` is the face row.
     *
     * @return list<array{query: string}>
     */
    private function queriesWith(string ...$needles): array
    {
        return array_values(array_filter(
            DB::getQueryLog(),
            fn (array $q): bool => ! array_filter($needles, fn (string $n): bool => ! str_contains($q['query'], $n)),
        ));
    }

    /**
     * The picture strip's three source reads. Told apart from the talk preview, which names the same
     * image table in an `exists` of its own, by the cap only the strip asks for.
     *
     * @return list<array{query: string}>
     */
    private function photoQueries(): array
    {
        return [
            ...$this->queriesWith('group_message_images', 'limit 8'),
            ...$this->queriesWith('group_topic_images', 'limit 8'),
            ...$this->queriesWith('group_event_images', 'limit 8'),
        ];
    }

    public function test_the_shipped_group_page_is_untouched_while_the_switch_is_off(): void
    {
        $category = GroupCategory::factory()->create();
        $group = Group::factory()->create(['group_category_id' => $category->getKey()]);
        Group::factory()->create(['group_category_id' => $category->getKey()]);
        $viewer = Member::factory()->create();
        $this->picture($this->topic($group, '2026-08-16 10:00:00', $viewer));

        DB::enableQueryLog();
        $this->actingAs($viewer)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('community/show')
                ->where('group.id', $group->getKey())
                ->where('group.name', $group->name)
                ->where('group.registerPolicy', 'open')
                ->where('viewerRole', null)
                ->where('canJoin', true)
                ->has('members')
                ->has('recentTopics', 1)
                ->has('recentEvents')
                ->where('canViewTalk', true)
                // The unified payload's own keys are absent while the switch is off.
                ->missing('categoryGroups')
                ->missing('recentPhotos')
            );
        $experiment = [...$this->queriesWith('members_count', 'group_category_id'), ...$this->photoQueries()];
        DB::disableQueryLog();

        $this->assertSame([], $experiment, 'the switched-off experiment still cost the page a query');
    }

    public function test_the_switch_swaps_the_page_for_the_same_group(): void
    {
        $file = File::factory()->create();
        $category = GroupCategory::factory()->create();
        $group = Group::factory()->create([
            'file_id' => $file->getKey(),
            'group_category_id' => $category->getKey(),
            'description' => 'what this group is for',
        ]);
        $viewer = Member::factory()->create();
        $this->join($group, $viewer);
        $this->unifiedOn();

        $this->actingAs($viewer)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('unified/group')
                ->where('group.id', $group->getKey())
                ->where('group.name', $group->name)
                ->where('group.bio', 'what this group is for')
                ->where('group.categoryName', $category->name)
                ->where('group.memberCount', 1)
                ->where('group.registerPolicy', 'open')
                // The hero paints a wide cover, so it takes the two rungs the header's srcset needs.
                ->where('group.avatarUrl', $file->thumbnailUrl(640, 640, square: true))
                ->where('group.avatarUrlLarge', $file->thumbnailUrl(1200, 1200, square: true))
                ->where('group.avatarColor', null)
                ->where('group.isAi', false)
                ->where('viewerRole', 'member')
                ->where('isPending', false)
                ->where('isTransferNominee', false)
                ->where('canManage', false)
                ->where('canJoin', false)
                ->where('canLeave', true)
                ->has('members', 1)
                ->where('members.0.id', $viewer->getKey())
                ->where('members.0.href', "/member/{$viewer->getKey()}")
                ->has('categoryGroups')
                ->has('recentTopics')
                ->where('canPostTopic', true)
                ->has('recentEvents')
                ->where('canPostEvent', true)
                ->where('canViewTalk', true)
                ->where('talkPreview', null)
                ->where('talkUnread', 0)
                ->has('recentPhotos')
            );
    }

    public function test_the_page_reads_each_of_its_sources_once(): void
    {
        $group = Group::factory()->create();
        $viewer = Member::factory()->create();
        $this->join($group, $viewer);
        $this->topic($group, '2026-08-16 10:00:00', $viewer);
        $this->event($group, '2026-08-16 10:00:00', $viewer);
        $this->unifiedOn();

        DB::enableQueryLog();
        $this->actingAs($viewer)->get("/groups/{$group->getKey()}")->assertOk();
        $faces = $this->queriesWith('group_members', 'limit 9');
        $topics = $this->queriesWith('group_topics', 'comments_count');
        $events = $this->queriesWith('participants_count');
        // The Classic details box names the admin and sub-admins; Modern never asks for them.
        $staff = $this->queriesWith('group_members', '"role" in');
        DB::disableQueryLog();

        $this->assertCount(1, $faces, 'the face row was read more than once');
        $this->assertCount(1, $topics, 'the recent topics were read more than once');
        $this->assertCount(1, $events, 'the recent events were read more than once');
        $this->assertSame([], $staff, 'a Classic-only query ran for a Modern page');
    }

    public function test_a_stranger_to_an_open_group_is_offered_the_way_in(): void
    {
        $group = Group::factory()->create();
        $viewer = Member::factory()->create();
        $this->unifiedOn();

        $this->actingAs($viewer)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('viewerRole', null)
                ->where('isPending', false)
                ->where('canJoin', true)
                ->where('canLeave', false)
                ->where('canManage', false)
                ->where('group.registerPolicy', 'open')
            );
    }

    public function test_a_stranger_to_an_approval_group_is_offered_the_application(): void
    {
        // Same entry, different word on it: the button's label is drawn from the policy.
        $group = Group::factory()->approval()->create();
        $viewer = Member::factory()->create();
        $this->unifiedOn();

        $this->actingAs($viewer)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canJoin', true)
                ->where('group.registerPolicy', 'approval')
            );
    }

    public function test_a_pending_applicant_is_told_they_are_waiting_instead(): void
    {
        $group = Group::factory()->approval()->create();
        $viewer = Member::factory()->create();
        $this->apply($group, $viewer);
        $this->unifiedOn();

        $this->actingAs($viewer)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('isPending', true)
                ->where('canJoin', false)
                ->where('viewerRole', null)
            );
    }

    public function test_a_sub_admin_manages_without_the_admin_only_entry(): void
    {
        $group = Group::factory()->create();
        $viewer = Member::factory()->create();
        $this->join($group, $viewer, 'subAdmin');
        $this->unifiedOn();

        $this->actingAs($viewer)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('viewerRole', 'sub_admin')
                ->where('canManage', true)
                // The pending-members link is the admin's alone, and the client keys it off this.
                ->where('canLeave', true)
            );
    }

    public function test_an_admin_manages_and_cannot_leave(): void
    {
        $group = Group::factory()->create();
        $viewer = Member::factory()->create();
        $this->join($group, $viewer, 'admin');
        $this->unifiedOn();

        $this->actingAs($viewer)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('viewerRole', 'admin')
                ->where('canManage', true)
                // The sole admin must hand the group on before leaving it.
                ->where('canLeave', false)
            );
    }

    public function test_the_transfer_nominee_is_asked_to_take_over(): void
    {
        $group = Group::factory()->create();
        $nominee = Member::factory()->create();
        $this->join($group, $nominee);
        $group->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();
        $this->unifiedOn();

        $this->actingAs($nominee)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('isTransferNominee', true));

        // The errand is the nominee's alone.
        $other = Member::factory()->create();
        $this->join($group, $other);
        $this->actingAs($other)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('isTransferNominee', false));
    }

    public function test_a_members_only_group_tells_a_stranger_nothing_and_reads_nothing(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $author = Member::factory()->create();
        $this->join($group, $author);
        $stranger = Member::factory()->create();
        $this->picture($this->message($group, '2026-08-16 10:00:00', $author));
        $this->picture($this->topic($group, '2026-08-16 10:00:00', $author));
        $this->picture($this->event($group, '2026-08-16 10:00:00', $author));
        $this->unifiedOn();

        DB::enableQueryLog();
        $this->actingAs($stranger)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('unified/group')
                ->where('recentTopics', null)
                ->where('recentEvents', null)
                ->where('canViewTalk', false)
                ->where('recentPhotos', [])
            );
        $read = [
            ...$this->queriesWith('comments_count'),
            ...$this->queriesWith('participants_count'),
            ...$this->queriesWith('images_exists'),
            ...$this->photoQueries(),
        ];
        DB::disableQueryLog();

        $this->assertSame([], $read, 'a refused gate still read what it guards');
    }

    public function test_photos_mix_the_three_sources_newest_parent_first(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $this->join($group, $author);
        $event = $this->event($group, '2026-08-16 10:00:00', $author);
        $topic = $this->topic($group, '2026-08-16 10:01:00', $author);
        $message = $this->message($group, '2026-08-16 10:02:00', $author);
        $this->picture($event);
        $this->picture($topic);
        // Two on one parent: inside a parent the author's own slot order decides.
        $first = $this->picture($message, number: 1);
        $second = $this->picture($message, number: 2);
        // Another group's picture is another group's business, however new it is.
        $elsewhere = Group::factory()->create();
        $this->join($elsewhere, $author);
        $strayFile = $this->picture($this->message($elsewhere, '2026-08-16 11:00:00', $author));
        $this->unifiedOn();

        $messageHref = "/groups/{$group->getKey()}/talk?m={$message->getKey()}";

        $this->actingAs($author)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentPhotos', 4)
                ->where('recentPhotos.0.source', 'talk')
                ->where('recentPhotos.0.href', $messageHref)
                ->where('recentPhotos.1.href', $messageHref)
                ->where('recentPhotos.2.source', 'topic')
                ->where('recentPhotos.2.href', "/topics/{$topic->getKey()}")
                ->where('recentPhotos.3.source', 'event')
                ->where('recentPhotos.3.href', "/events/{$event->getKey()}")
            )
            // The slot order inside the parent, and no trace of the other group's picture.
            ->assertSee($first->name)
            ->assertSee($second->name)
            ->assertDontSee($strayFile->name);
    }

    public function test_the_strip_caps_at_eight_without_disturbing_the_order(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $this->join($group, $author);
        $older = $this->topic($group, '2026-08-16 10:00:00', $author);
        $newer = $this->message($group, '2026-08-16 10:05:00', $author);
        foreach (range(1, 5) as $number) {
            $this->picture($newer, $number);
        }
        $cut = [];
        foreach (range(1, 5) as $number) {
            $cut[$number] = $this->picture($older, $number);
        }
        $this->unifiedOn();

        $this->actingAs($author)->get("/groups/{$group->getKey()}")
            ->assertInertia(function (AssertableInertia $page) use ($newer, $older, $group) {
                $page->has('recentPhotos', 8);
                foreach (range(0, 4) as $i) {
                    $page->where("recentPhotos.{$i}.href", "/groups/{$group->getKey()}/talk?m={$newer->getKey()}");
                }
                foreach (range(5, 7) as $i) {
                    $page->where("recentPhotos.{$i}.href", "/topics/{$older->getKey()}");
                }

                return $page;
            })
            // The cap cuts the oldest slots of the oldest parent, and cuts them entirely.
            ->assertDontSee($cut[4]->name)
            ->assertDontSee($cut[5]->name);
    }

    public function test_a_file_the_policy_refuses_leaves_no_trace_in_the_strip(): void
    {
        // The read gate on the board is not a permission on the file: every candidate is asked again,
        // and one whose file no longer belongs to anything is left out in silence.
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $this->join($group, $author);
        $topic = $this->topic($group, '2026-08-16 10:00:00', $author);
        $allowed = $this->picture($topic, number: 1);
        $refused = $this->picture($topic, number: 2, linked: false);
        $this->unifiedOn();

        $this->actingAs($author)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('recentPhotos', 1))
            ->assertSee($allowed->name)
            ->assertDontSee($refused->name);
    }

    public function test_a_join_row_pointing_at_a_file_owned_elsewhere_leaves_no_trace(): void
    {
        // FilePolicy authorizes against the owner the FILE declares, not the join row that pointed
        // here — so a row whose file belongs elsewhere could pass the Gate on the other owner's
        // terms. The strip must also demand that the file's declared owner IS this parent.
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $this->join($group, $author);
        $message = $this->message($group, '2026-08-16 10:00:00', $author);
        $allowed = $this->picture($message, number: 1);

        // A file owned by another group's message the viewer may read: Gate would say yes.
        $other = Group::factory()->create();
        $this->join($other, $author);
        $foreign = $this->picture($this->message($other, '2026-08-16 09:00:00', $author));
        GroupMessageImage::factory()->create([
            'group_message_id' => $message->getKey(), 'file_id' => $foreign->getKey(), 'number' => 2,
        ]);

        // A file owned by a different kind of entity entirely.
        $diaryOwned = File::factory()->create(['related_entity_type' => 'diary', 'related_entity_id' => $message->getKey()]);
        GroupMessageImage::factory()->create([
            'group_message_id' => $message->getKey(), 'file_id' => $diaryOwned->getKey(), 'number' => 3,
        ]);

        // A file whose declared owner row is gone.
        $dangling = File::factory()->create(['related_entity_type' => 'groupMessage', 'related_entity_id' => 999999]);
        GroupMessageImage::factory()->create([
            'group_message_id' => $message->getKey(), 'file_id' => $dangling->getKey(), 'number' => 4,
        ]);

        $this->unifiedOn();

        // The foreign file's URL appears once for its own parent (in the other group), never here.
        $this->actingAs($author)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->has('recentPhotos', 1))
            ->assertSee($allowed->name)
            ->assertDontSee($foreign->name)
            ->assertDontSee($diaryOwned->name)
            ->assertDontSee($dangling->name);
    }

    public function test_a_switched_off_unit_drops_its_pictures_and_its_query(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $this->join($group, $author);
        $message = $this->message($group, '2026-08-16 10:00:00', $author);
        $this->picture($message);
        $this->picture($this->topic($group, '2026-08-16 10:01:00', $author));

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTopicEnabled, false);
        $this->unifiedOn();

        DB::enableQueryLog();
        $this->actingAs($author)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recentTopics', null)
                ->has('recentPhotos', 1)
                ->where('recentPhotos.0.source', 'talk')
                ->where('recentPhotos.0.href', "/groups/{$group->getKey()}/talk?m={$message->getKey()}")
            );
        $topicQueries = $this->queriesWith('group_topic_images', 'limit 8');
        DB::disableQueryLog();

        $this->assertSame([], $topicQueries, 'a switched-off unit still read its pictures');
    }

    public function test_the_same_category_reads_the_same_to_everyone(): void
    {
        $category = GroupCategory::factory()->create();
        $group = Group::factory()->create(['group_category_id' => $category->getKey()]);
        $big = Group::factory()->create(['group_category_id' => $category->getKey()]);
        $small = Group::factory()->create(['group_category_id' => $category->getKey()]);
        Group::factory()->create(['group_category_id' => GroupCategory::factory()->create()->getKey()]);
        foreach (range(1, 2) as $ignored) {
            $this->join($big, Member::factory()->create());
        }
        $this->join($small, Member::factory()->create());

        $member = Member::factory()->create();
        $this->join($group, $member);
        $stranger = Member::factory()->create();
        $this->unifiedOn();

        // Biggest first, and the group the page is about is not one of its own neighbours.
        $expected = fn (AssertableInertia $page) => $page
            ->has('categoryGroups', 2)
            ->where('categoryGroups.0.id', $big->getKey())
            ->where('categoryGroups.0.memberCount', 2)
            ->where('categoryGroups.0.href', "/groups/{$big->getKey()}")
            ->where('categoryGroups.1.id', $small->getKey())
            ->where('categoryGroups.1.memberCount', 1);

        $this->actingAs($member)->get("/groups/{$group->getKey()}")->assertInertia($expected);
        // The list is a projection of "any member may view any group", so membership cannot move it.
        $this->actingAs($stranger)->get("/groups/{$group->getKey()}")->assertInertia($expected);
    }

    public function test_groups_of_the_same_size_are_ordered_newest_first(): void
    {
        $category = GroupCategory::factory()->create();
        $group = Group::factory()->create(['group_category_id' => $category->getKey()]);
        $older = Group::factory()->create(['group_category_id' => $category->getKey()]);
        $newer = Group::factory()->create(['group_category_id' => $category->getKey()]);
        $viewer = Member::factory()->create();
        $this->unifiedOn();

        $this->actingAs($viewer)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categoryGroups.0.id', $newer->getKey())
                ->where('categoryGroups.1.id', $older->getKey())
            );
    }

    public function test_a_group_in_no_category_has_no_neighbours_to_show(): void
    {
        $group = Group::factory()->create(['group_category_id' => null]);
        Group::factory()->create(['group_category_id' => null]);
        $viewer = Member::factory()->create();
        $this->unifiedOn();

        $this->actingAs($viewer)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categoryGroups', [])
                ->where('group.categoryName', null)
            );
    }

    public function test_the_talk_card_previews_the_last_thing_said(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $this->join($group, $author);
        $viewer = Member::factory()->create();
        $this->join($group, $viewer);
        GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'body' => 'the latest word',
        ]);
        $this->unifiedOn();

        $this->actingAs($viewer)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canViewTalk', true)
                ->where('talkPreview.body', 'the latest word')
                ->where('talkPreview.authorName', $author->name)
                ->where('talkUnread', 1)
            );
    }
}
