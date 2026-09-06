<?php

namespace Tests\Feature\Timeline\Listeners;

use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\Events\DiaryPosted;
use App\Features\GroupEvent\Events\EventPosted;
use App\Features\GroupTopic\Events\TopicPosted;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Events\TimelinePostPosted;
use App\Features\Timeline\TimelinePostOrigin;
use App\Jobs\BroadcastTimelinePosted;
use App\Jobs\SyncLinkCard;
use App\Listeners\Timeline\AnnounceOnTimeline;
use App\Listeners\Timeline\NotifyTimelinePosted;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AnnounceOnTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.site_locale' => 'en']);
        URL::forceRootUrl('http://sns.example');
    }

    public function test_a_diary_is_announced_as_its_author_with_its_audience_when_the_switch_is_on(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiaryAutoTimelinePost, true);
        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'title' => 'Hello', 'visibility' => Visibility::Friends]);

        app(AnnounceOnTimeline::class)->handleDiaryPosted(new DiaryPosted($diary, $author));

        $this->assertDatabaseHas('timeline_posts', [
            'member_id' => $author->getKey(),
            'body' => "[Diary] Hello\nhttp://sns.example/diary/{$diary->getKey()}",
            'visibility' => Visibility::Friends->value,
            'in_reply_to_id' => null,
        ]);
    }

    public function test_nothing_is_posted_while_the_switch_or_the_timeline_unit_is_off(): void
    {
        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey()]);

        app(AnnounceOnTimeline::class)->handleDiaryPosted(new DiaryPosted($diary, $author));
        $this->assertSame(0, TimelinePost::count());

        $this->setSnsSetting(SnsSettingKey::DiaryAutoTimelinePost, true);
        $this->setSnsSetting(SnsSettingKey::FeatureTimelineEnabled, false);
        app(AnnounceOnTimeline::class)->handleDiaryPosted(new DiaryPosted($diary, $author));
        $this->assertSame(0, TimelinePost::count());
    }

    public function test_an_open_diary_is_announced_to_members_while_the_timeline_cannot_be_web_public(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiaryAutoTimelinePost, true);
        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Open]);

        app(AnnounceOnTimeline::class)->handleDiaryPosted(new DiaryPosted($diary, $author));
        $this->assertSame(Visibility::Members, TimelinePost::sole()->visibility);

        TimelinePost::query()->delete();
        $this->setSnsSetting(SnsSettingKey::TimelineAllowWebPublic, true);
        app(AnnounceOnTimeline::class)->handleDiaryPosted(new DiaryPosted($diary, $author));
        $this->assertSame(Visibility::Open, TimelinePost::sole()->visibility);
    }

    public function test_a_topic_and_an_event_in_an_open_group_are_announced_to_members(): void
    {
        $this->setSnsSetting(SnsSettingKey::GroupAutoTimelinePost, true);
        $author = Member::factory()->create();
        $group = Group::factory()->create(['name' => 'Runners', 'topic_read_access' => TopicReadAccess::Everyone]);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'name' => 'Marathon']);
        $event = GroupEvent::factory()->create([
            'group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'name' => 'Picnic',
            'open_date' => '2026-06-04 00:00:00', 'open_date_comment' => '13:00',
        ]);

        $listener = app(AnnounceOnTimeline::class);
        $listener->handleTopicPosted(new TopicPosted($topic, $author));
        $listener->handleEventPosted(new EventPosted($event, $author));

        $this->assertDatabaseHas('timeline_posts', [
            'body' => "[Group topic] Marathon (Runners)\nhttp://sns.example/topics/{$topic->getKey()}",
            'visibility' => Visibility::Members->value,
        ]);
        $this->assertDatabaseHas('timeline_posts', [
            'body' => "[Group event] Picnic (Runners, June 4, 2026 13:00)\nhttp://sns.example/events/{$event->getKey()}",
            'visibility' => Visibility::Members->value,
        ]);
    }

    public function test_a_members_only_group_gets_no_line(): void
    {
        $this->setSnsSetting(SnsSettingKey::GroupAutoTimelinePost, true);
        $author = Member::factory()->create();
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        app(AnnounceOnTimeline::class)->handleTopicPosted(new TopicPosted($topic, $author));

        $this->assertSame(0, TimelinePost::count());
    }

    public function test_the_line_is_written_in_the_site_locale_not_the_authors(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiaryAutoTimelinePost, true);
        config(['openpne.site_locale' => 'ja']);
        app()->setLocale('en'); // what SetLocale resolved for the author's request
        $author = Member::factory()->create(['locale' => 'en']);
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'title' => 'こんにちは']);

        app(AnnounceOnTimeline::class)->handleDiaryPosted(new DiaryPosted($diary, $author));

        $this->assertStringStartsWith("[日記] こんにちは\n", TimelinePost::sole()->body);
    }

    public function test_a_long_title_is_cut_and_the_url_is_kept_whole(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiaryAutoTimelinePost, true);
        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'title' => str_repeat('x', 200)]);

        app(AnnounceOnTimeline::class)->handleDiaryPosted(new DiaryPosted($diary, $author));

        $body = TimelinePost::sole()->body;
        $this->assertSame(140, mb_strlen($body));
        $this->assertStringEndsWith("…\nhttp://sns.example/diary/{$diary->getKey()}", $body);
    }

    public function test_a_diary_the_author_keeps_private_is_announced_privately(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiaryAutoTimelinePost, true);
        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Private]);

        app(AnnounceOnTimeline::class)->handleDiaryPosted(new DiaryPosted($diary, $author));

        $this->assertSame(Visibility::Private, TimelinePost::sole()->visibility);
    }

    public function test_a_topic_is_not_announced_while_the_timeline_unit_is_off(): void
    {
        $this->setSnsSetting(SnsSettingKey::GroupAutoTimelinePost, true);
        $this->setSnsSetting(SnsSettingKey::FeatureTimelineEnabled, false);
        $author = Member::factory()->create();
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        app(AnnounceOnTimeline::class)->handleTopicPosted(new TopicPosted($topic, $author));

        $this->assertSame(0, TimelinePost::count());
    }

    public function test_a_body_of_140_code_points_of_four_byte_emoji_is_stored_whole(): void
    {
        // varchar(140) counts characters, not bytes: the MySQL lane is where this has teeth.
        $this->setSnsSetting(SnsSettingKey::DiaryAutoTimelinePost, true);
        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'title' => str_repeat("\u{1F600}", 200)]);

        app(AnnounceOnTimeline::class)->handleDiaryPosted(new DiaryPosted($diary, $author));

        $body = TimelinePost::sole()->body;
        $this->assertSame(140, mb_strlen($body));
        $this->assertStringEndsWith("…\nhttp://sns.example/diary/{$diary->getKey()}", $body);
    }

    public function test_a_failed_insert_is_logged_and_never_fails_the_creating_request(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiaryAutoTimelinePost, true);
        Log::spy();
        $this->mock(CreateTimelinePost::class, function (MockInterface $mock): void {
            $mock->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('deadlock'));
        });
        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey()]);

        app(AnnounceOnTimeline::class)->handleDiaryPosted(new DiaryPosted($diary, $author));

        $this->assertSame(0, TimelinePost::count());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_url_that_cannot_fit_skips_the_post_and_logs_a_warning(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiaryAutoTimelinePost, true);
        URL::forceRootUrl('http://'.str_repeat('h', 130).'.example');
        Log::spy();
        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey()]);

        app(AnnounceOnTimeline::class)->handleDiaryPosted(new DiaryPosted($diary, $author));

        $this->assertSame(0, TimelinePost::count());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_announcement_fans_out_no_timeline_notification(): void
    {
        Bus::fake([BroadcastTimelinePosted::class]);
        $author = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey()]);

        app(NotifyTimelinePosted::class)->handle(new TimelinePostPosted($post, $author, [], TimelinePostOrigin::Auto));
        Bus::assertNotDispatched(BroadcastTimelinePosted::class);

        app(NotifyTimelinePosted::class)->handle(new TimelinePostPosted($post, $author, []));
        Bus::assertDispatched(BroadcastTimelinePosted::class);
    }

    public function test_creating_a_diary_announces_it_through_the_real_event_and_queues_its_link_card(): void
    {
        Bus::fake([SyncLinkCard::class, BroadcastTimelinePosted::class]);
        $this->setSnsSetting(SnsSettingKey::DiaryAutoTimelinePost, true);
        $author = Member::factory()->create();

        $diary = app(CreateDiary::class)($author, new DiaryFormData('Title', 'Body', Visibility::Members));

        $post = TimelinePost::sole();
        $this->assertSame("[Diary] Title\nhttp://sns.example/diary/{$diary->getKey()}", $post->body);
        Bus::assertDispatched(SyncLinkCard::class, fn (SyncLinkCard $job) => $job->model === TimelinePost::class && $job->id === (int) $post->getKey());
        Bus::assertNotDispatched(BroadcastTimelinePosted::class);
    }

    public function test_a_url_inside_the_title_takes_the_card(): void
    {
        // A card follows the body's first URL; the line comes before the record URL by design.
        Bus::fake([SyncLinkCard::class]);
        $this->setSnsSetting(SnsSettingKey::DiaryAutoTimelinePost, true);
        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey(), 'title' => 'see https://example.com/x']);

        app(AnnounceOnTimeline::class)->handleDiaryPosted(new DiaryPosted($diary, $author));

        $this->assertSame("[Diary] see https://example.com/x\nhttp://sns.example/diary/{$diary->getKey()}", TimelinePost::sole()->body);
    }
}
