<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Features\CommunityEvent\Actions\CreateEvent;
use App\Features\CommunityEvent\Actions\UpdateEvent;
use App\Features\CommunityEvent\Data\CommunityEventFormData;
use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Actions\UpdateDiary;
use App\Features\Diary\Data\DiaryFormData;
use App\Features\GroupTopic\Actions\CreateTopic;
use App\Features\GroupTopic\Actions\UpdateTopic;
use App\Features\GroupTopic\Data\GroupTopicFormData;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Files\ImageEdit;
use App\Jobs\SyncLinkCard;
use App\Models\CommunityEvent;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\BodyFormat;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * That the pipeline is actually connected — the part no unit test can show, because every piece of
 * it passed its own tests while nothing called any of them.
 */
class LinkCardWiringTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, true);
        $this->member = Member::factory()->create();
        Queue::fake();
    }

    public function test_posting_a_diary_queues_a_sync(): void
    {
        $this->actingAs($this->member)
            ->post(route('diary.store'), ['title' => 'T', 'body' => 'See https://example.com/a', 'visibility' => Visibility::Open->value])
            ->assertRedirect();

        Queue::assertPushed(SyncLinkCard::class);
    }

    public function test_editing_a_diary_body_detaches_the_old_card_in_the_same_write(): void
    {
        // Between the write and the job there is a window in which the page renders. The card must
        // already be gone by then, or the new text shows under the previous body's card.
        $card = LinkCard::factory()->create();
        $diary = Diary::factory()->for($this->member)->create([
            'body' => 'Old https://example.com/old',
            'link_card_id' => $card->id,
            'link_card_synced_at' => CarbonImmutable::now(),
        ]);

        $this->actingAs($this->member)
            ->post(route('diary.update', $diary), ['title' => $diary->title, 'body' => 'New https://example.com/new', 'visibility' => $diary->visibility->value])
            ->assertRedirect();

        $diary->refresh();
        $this->assertNull($diary->link_card_id);
        $this->assertNull($diary->link_card_synced_at);
        Queue::assertPushed(SyncLinkCard::class);
    }

    public function test_editing_something_other_than_the_body_keeps_the_card(): void
    {
        // Clearing on every edit would re-fetch the same page because someone fixed a typo in the
        // title or changed who can see it.
        $card = LinkCard::factory()->create();
        $diary = Diary::factory()->for($this->member)->create([
            'title' => 'Before',
            'body' => 'See https://example.com/a',
            'link_card_id' => $card->id,
            'link_card_synced_at' => CarbonImmutable::now(),
        ]);

        $this->actingAs($this->member)
            ->post(route('diary.update', $diary), ['title' => 'After', 'body' => $diary->body, 'visibility' => $diary->visibility->value])
            ->assertRedirect();

        $diary->refresh();
        $this->assertSame($card->id, $diary->link_card_id);
        $this->assertNotNull($diary->link_card_synced_at);
    }

    #[DataProvider('bodyKinds')]
    public function test_posting_any_body_kind_queues_a_sync(string $kind): void
    {
        // One assertion per call site. A single representative would go green with five of the six
        // dispatches deleted, which is precisely the failure this PR exists to prevent.
        $record = $this->{'create'.$kind}();

        Queue::assertPushed(SyncLinkCard::class, fn (SyncLinkCard $job): bool => $job->model === $record::class && $job->id === $record->id);
    }

    #[DataProvider('editableKinds')]
    public function test_editing_any_body_kind_detaches_its_card(string $kind): void
    {
        $record = $this->{'create'.$kind}();
        $card = LinkCard::factory()->create();
        $record->forceFill(['link_card_id' => $card->id, 'link_card_synced_at' => CarbonImmutable::now()])->saveQuietly();

        // Re-faked so the create's own job is not what the assertions below see: without this the
        // edit's dispatch could be missing entirely and the count would still look right.
        Queue::fake();

        $this->{'edit'.$kind.'Body'}($record);

        $record->refresh();
        $this->assertNull($record->link_card_id, "{$kind}: the previous body's card survived the edit.");
        $this->assertNull($record->link_card_synced_at, "{$kind}: the new body was left marked as examined.");

        Queue::assertPushed(
            SyncLinkCard::class,
            fn (SyncLinkCard $job): bool => $job->model === $record::class
                && $job->id === $record->id
                && $job->afterCommit === true,
        );
        Queue::assertPushed(SyncLinkCard::class, 1);
    }

    public function test_changing_only_the_format_detaches_the_card(): void
    {
        // The other field the card depends on. A body reads differently as Markdown — a bare URL in
        // a code span stops being a link — so the card has to be worked out again even though the
        // text is byte-for-byte identical.
        $diary = $this->createDiary();
        $card = LinkCard::factory()->create();
        $diary->forceFill(['link_card_id' => $card->id, 'link_card_synced_at' => CarbonImmutable::now()])->saveQuietly();

        $this->app->make(UpdateDiary::class)(
            $this->member,
            $diary,
            new DiaryFormData(title: $diary->title, body: $diary->body, visibility: $diary->visibility, format: BodyFormat::Markdown),
            ImageEdit::none(),
        );

        $diary->refresh();
        $this->assertSame(BodyFormat::Markdown, $diary->format);
        $this->assertNull($diary->link_card_id, 'A format change leaves the body reading differently, so the card must be redone.');
        $this->assertNull($diary->link_card_synced_at);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bodyKinds(): array
    {
        // Diary and the community bodies carry a format column; a timeline post does not, and the
        // sync has a branch for that.
        return ['Diary' => ['Diary'], 'Topic' => ['Topic'], 'Event' => ['Event'], 'TimelinePost' => ['TimelinePost']];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function editableKinds(): array
    {
        // A timeline post has no update action — it cannot be edited at all.
        return ['Diary' => ['Diary'], 'Topic' => ['Topic'], 'Event' => ['Event']];
    }

    #[DataProvider('bodyKinds')]
    public function test_every_sync_is_marked_to_wait_for_the_commit(string $kind): void
    {
        // The job re-reads the record by id, so queued before the commit a worker can find nothing —
        // or, after an edit, find the text as it was before it and conclude the old URL is current.
        //
        // Asserted as the flag on the job rather than by opening a transaction and watching: the
        // deferral lives in Illuminate\Queue\Queue::enqueueUsing, and QueueFake::push does not go
        // through it, so a test that wrapped this in DB::transaction would see the job queued
        // immediately whether or not the call site asked to wait — proving nothing either way. What
        // is ours to get right is that every call site asks.
        $this->{'create'.$kind}();

        Queue::assertPushed(SyncLinkCard::class, fn (SyncLinkCard $job): bool => $job->afterCommit === true);
    }

    public function test_viewing_a_diary_nobody_has_examined_queues_a_sync(): void
    {
        $diary = Diary::factory()->for($this->member)->create([
            'body' => 'See https://example.com/a',
            'link_card_synced_at' => null,
        ]);

        $this->actingAs($this->member)->get(route('diary.show', $diary))->assertOk();

        Queue::assertPushed(SyncLinkCard::class);
    }

    public function test_a_diary_feed_queues_nothing(): void
    {
        // A list renders many entries; asking on each would queue a page's worth of jobs for someone
        // scrolling past.
        Diary::factory()->count(3)->for($this->member)->create([
            'body' => 'See https://example.com/a',
            'link_card_synced_at' => null,
        ]);

        $this->actingAs($this->member)->get(route('diary.list'))->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_viewing_a_topic_queues_a_sync(): void
    {
        $topic = $this->topic();

        $this->actingAs($this->member)->get(route('group.topics.show', $topic))->assertOk();

        Queue::assertPushed(SyncLinkCard::class);
    }

    public function test_viewing_an_event_queues_a_sync(): void
    {
        $event = $this->event();

        $this->actingAs($this->member)->get(route('communityEvent.show', $event))->assertOk();

        Queue::assertPushed(SyncLinkCard::class);
    }

    public function test_viewing_a_timeline_post_queues_a_sync_for_the_root_only(): void
    {
        // Replies live in the same table and carry the column, but render as a thread underneath
        // where a stack of cards would read as noise — and one job per reply is what the detail-page
        // rule exists to avoid.
        $post = TimelinePost::factory()->for($this->member)->create([
            'body' => 'Root https://example.com/root',
            'link_card_synced_at' => null,
        ]);
        TimelinePost::factory()->count(2)->for($this->member)->create([
            'in_reply_to_id' => $post->id,
            'body' => 'Reply https://example.com/reply',
            'link_card_synced_at' => null,
        ]);

        $this->actingAs($this->member)->get(route('timeline.show', $post))->assertOk();

        Queue::assertPushed(SyncLinkCard::class, 1);
        Queue::assertPushed(SyncLinkCard::class, fn (SyncLinkCard $job): bool => $job->id === $post->id);
    }

    public function test_a_reply_permalink_redirects_without_queueing(): void
    {
        // The redirect never renders the thread, so there is nothing to prepare.
        $post = TimelinePost::factory()->for($this->member)->create(['link_card_synced_at' => null]);
        $reply = TimelinePost::factory()->for($this->member)->create([
            'in_reply_to_id' => $post->id,
            'link_card_synced_at' => null,
        ]);

        $this->actingAs($this->member)->get(route('timeline.show', $reply))->assertRedirect();

        Queue::assertNothingPushed();
    }

    public function test_nothing_is_queued_while_the_setting_is_off(): void
    {
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);
        $diary = Diary::factory()->for($this->member)->create([
            'body' => 'See https://example.com/a',
            'link_card_synced_at' => null,
        ]);

        $this->actingAs($this->member)->get(route('diary.show', $diary))->assertOk();

        Queue::assertNothingPushed();
    }

    private function createDiary(): Diary
    {
        return $this->app->make(CreateDiary::class)(
            $this->member,
            new DiaryFormData(title: 'T', body: 'See https://example.com/a', visibility: Visibility::Open, format: BodyFormat::Plain),
        );
    }

    private function createTopic(): GroupTopic
    {
        return $this->app->make(CreateTopic::class)(
            $this->member,
            $this->joinedGroup(),
            new GroupTopicFormData(name: 'T', body: 'See https://example.com/a', format: BodyFormat::Plain),
        );
    }

    private function createEvent(): CommunityEvent
    {
        return $this->app->make(CreateEvent::class)(
            $this->member,
            $this->joinedGroup(),
            $this->eventForm(),
        );
    }

    private function createTimelinePost(): TimelinePost
    {
        return $this->app->make(CreateTimelinePost::class)(
            $this->member,
            new TimelinePostFormData(body: 'See https://example.com/a', visibility: Visibility::Open),
        );
    }

    private function editDiaryBody(Diary $diary): void
    {
        $this->app->make(UpdateDiary::class)(
            $this->member,
            $diary,
            new DiaryFormData(title: $diary->title, body: 'Rewritten https://example.com/b', visibility: $diary->visibility, format: BodyFormat::Plain),
            ImageEdit::none(),
        );
    }

    private function editTopicBody(GroupTopic $topic): void
    {
        $this->app->make(UpdateTopic::class)(
            $this->member,
            $topic,
            new GroupTopicFormData(name: $topic->name, body: 'Rewritten https://example.com/b', format: BodyFormat::Plain),
            ImageEdit::none(),
        );
    }

    private function editEventBody(CommunityEvent $event): void
    {
        $this->app->make(UpdateEvent::class)(
            $this->member,
            $event,
            $this->eventForm('Rewritten https://example.com/b'),
            ImageEdit::none(),
        );
    }

    private function eventForm(string $body = 'See https://example.com/a'): CommunityEventFormData
    {
        return new CommunityEventFormData(
            name: 'E',
            body: $body,
            open_date: '2027-01-01',
            open_date_comment: '',
            area: 'Somewhere',
            application_deadline: null,
            capacity: null,
            format: BodyFormat::Plain,
        );
    }

    private function joinedGroup(): Group
    {
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $this->member->getKey()]);

        return $group;
    }

    private function topic(): GroupTopic
    {
        $group = $this->joinedGroup();

        return GroupTopic::factory()->for($group)->for($this->member, 'member')->create([
            'body' => 'See https://example.com/a',
            'link_card_synced_at' => null,
        ]);
    }

    private function event(): CommunityEvent
    {
        $group = $this->joinedGroup();

        return CommunityEvent::factory()->for($group, 'community')->for($this->member, 'member')->create([
            'body' => 'See https://example.com/a',
            'link_card_synced_at' => null,
        ]);
    }
}
