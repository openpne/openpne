<?php

namespace App\Listeners\Timeline;

use App\Features\Diary\Events\DiaryPosted;
use App\Features\GroupEvent\Events\EventPosted;
use App\Features\GroupTopic\Events\TopicPosted;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Announcement;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Features\Timeline\TimelineAutoPost;
use App\Features\Timeline\TimelinePostOrigin;
use App\Features\Timeline\TimelineVisibility;
use App\Models\Group;
use App\Models\Member;
use App\Support\Feature;
use App\Support\LocalizedDate;
use App\Support\SiteLocale;
use App\Support\Visibility;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpenPNE 3's update_activity: a new diary, topic or event becomes one timeline line by its author.
 * The record is committed before this runs, so nothing here may fail the request that created it:
 * every reason not to post, a failed insert included, is a skip (docs/internals/timeline.md, "Automatic posts").
 */
class AnnounceOnTimeline
{
    public function __construct(
        private readonly Announcement $announcement,
        private readonly CreateTimelinePost $create,
    ) {}

    public function handleDiaryPosted(DiaryPosted $event): void
    {
        $this->guarded($event->author, function () use ($event): void {
            $this->announceDiary($event);
        });
    }

    public function handleTopicPosted(TopicPosted $event): void
    {
        $this->guarded($event->author, function () use ($event): void {
            $this->announceTopic($event);
        });
    }

    public function handleEventPosted(EventPosted $event): void
    {
        $this->guarded($event->author, function () use ($event): void {
            $this->announceEvent($event);
        });
    }

    private function announceDiary(DiaryPosted $event): void
    {
        if (! Feature::Timeline->enabled() || ! TimelineAutoPost::forDiaries()) {
            return;
        }

        $diary = $event->diary;
        // OpenPNE 3 kept an open diary's line inside the SNS unless activities could be open too;
        // a line stored Open would surface on the web once that switch is turned on later.
        $visibility = $diary->visibility === Visibility::Open && ! TimelineVisibility::allowsWebPublic()
            ? Visibility::Members
            : $diary->visibility;

        $this->post(
            $event->author,
            $this->announcement->diary((string) $diary->title, route('diary.show', $diary), $this->locale()),
            $visibility,
        );
    }

    private function announceTopic(TopicPosted $event): void
    {
        if (! $this->groupAnnounces($event->topic->group)) {
            return;
        }

        $this->post(
            $event->author,
            $this->announcement->topic((string) $event->topic->group->name, (string) $event->topic->name, route('group.topics.show', $event->topic), $this->locale()),
            Visibility::Members,
        );
    }

    private function announceEvent(EventPosted $event): void
    {
        $record = $event->event;
        if (! $this->groupAnnounces($record->group)) {
            return;
        }

        $comment = trim((string) $record->open_date_comment);
        $open = LocalizedDate::dateIn($record->open_date, $this->locale()).($comment === '' ? '' : ' '.$comment);

        $this->post(
            $event->author,
            $this->announcement->event((string) $record->group->name, (string) $record->name, $open, route('group.events.show', $record), $this->locale()),
            Visibility::Members,
        );
    }

    /** A members-only group gets no line: OpenPNE 3 posted one only its author could read. */
    private function groupAnnounces(Group $group): bool
    {
        return Feature::Timeline->enabled()
            && TimelineAutoPost::forGroups()
            && $group->topic_read_access === TopicReadAccess::Everyone;
    }

    private function post(Member $author, ?string $body, Visibility $visibility): void
    {
        if ($body === null) {
            Log::warning('Timeline announcement skipped: the URL alone exceeds the post length.', ['member_id' => $author->getKey()]);

            return;
        }

        ($this->create)($author, new TimelinePostFormData($body, $visibility), null, TimelinePostOrigin::Auto);
    }

    /** Whatever fails — a setting read, the rendering, the insert — is reported and the announcement dropped. */
    private function guarded(Member $author, Closure $announce): void
    {
        try {
            $announce();
        } catch (Throwable $e) {
            report($e);
            Log::warning('Timeline announcement skipped: it could not be posted.', ['member_id' => $author->getKey()]);
        }
    }

    private function locale(): string
    {
        return SiteLocale::default();
    }
}
