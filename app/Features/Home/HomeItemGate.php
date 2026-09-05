<?php

declare(strict_types=1);

namespace App\Features\Home;

use App\Features\Diary\DiaryAccess;
use App\Features\GroupTalk\Queries\TalkSampleDigest;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Home\Data\HydratedItem;
use App\Features\Timeline\TimelineAccess;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\HomeIssueItem;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Policies\MemberPolicy;
use App\Support\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Whether one ledger row still resolves for the member reading the issue: every row is asked again
 * through the source's own rule, and one that does not answer is dropped in silence
 * (docs/internals/home-issues.md, "Rendering"). It answers with the item rather than a boolean
 * because the talk arm has to read the room to know.
 */
final class HomeItemGate
{
    public function __construct(private readonly TalkSampleDigest $talk) {}

    public function resolve(Member $viewer, HomeIssueItem $item, ?Model $source): ?HydratedItem
    {
        $section = $item->section;
        $alias = (string) $item->source_type;

        // A section that does not hold this alias cannot be asked what gates it, and the front page
        // is not the place to raise whatever wrote such a row.
        if (! $section->allowsSource($alias)) {
            return null;
        }

        // The row outlives its source by design, so a dangling reference is data rather than a fault.
        if ($source === null) {
            return null;
        }

        // Read again here, not only at publication: an administrator switching a unit off hides its
        // rows without touching the ledger, and switching it back on brings them back.
        $unit = $section->unit($alias);
        if ($unit !== null && ! $unit->enabled()) {
            return null;
        }

        return match ($section) {
            HomeIssueSection::Stories => $this->story($viewer, $item, $source),
            HomeIssueSection::Talk => $this->burst($viewer, $item, $source),
            HomeIssueSection::Newcomers => $this->newcomer($viewer, $item, $source),
            // A new group is a door to knock on whatever its read access: this section shows none of
            // its contents, and the group page is open to any signed-in member.
            HomeIssueSection::NewGroups => new HydratedItem($item, $source),
            HomeIssueSection::UpcomingEvents => $this->calendarEntry($item, $source),
        };
    }

    /**
     * A story, through the rule its own feature applies to a single record. The eligibility half is
     * checked again here because an author may have narrowed the record since, and the viewer half —
     * a block, and the clearance it would otherwise widen — for the first time.
     */
    private function story(Member $viewer, HomeIssueItem $item, Model $source): ?HydratedItem
    {
        $allowed = match (true) {
            // A reply is not a story: it inherits its root's audience, and an issue that led with one
            // would quote half a conversation.
            $source instanceof TimelinePost => $source->in_reply_to_id === null
                && $source->visibility->value <= Visibility::Members->value
                && TimelineAccess::canView($viewer, $source),
            $source instanceof Diary => $source->visibility->value <= Visibility::Members->value
                && DiaryAccess::canView($viewer, $source),
            // A withdrawn author is not a refusal: the board keeps the record and both serializers
            // already draw the byline as a withdrawn member.
            $source instanceof GroupTopic, $source instanceof GroupEvent => $this->boardIsOpen($source),
            // Unreachable while the loader answers each alias with its own model; a drop rather than
            // an UnhandledMatchError, because nothing about a ledger row may reach the reader as a 500.
            default => false,
        };

        return $allowed ? new HydratedItem($item, $source) : null;
    }

    /**
     * `topic_read_access` IS the gate here: an Everyone group's talk is readable by any member
     * (GroupTalkAccess), so asking that after this would be asking a question with one answer.
     */
    private function burst(Member $viewer, HomeIssueItem $item, Model $source): ?HydratedItem
    {
        if (! $source instanceof Group || $source->topic_read_access !== TopicReadAccess::Everyone) {
            return null;
        }

        $window = $this->window($item);
        if ($window === null) {
            return null;
        }

        [$since, $until] = $window;

        // The stretch, not any message in it: the row stores no anchor, so a deleted message is
        // simply not there rather than a hole, and nothing left is nothing to report.
        $first = $this->talk->firstBetween($source, $since, $until);
        if ($first === null) {
            return null;
        }

        return new HydratedItem($item, $source, [
            'count' => $this->talk->countBetween($source, $since, $until),
            // The way in is the first surviving message rather than the instant the window opens on:
            // an issue pointing at an hour whose messages have all gone would land the reader in a
            // stretch nobody can read.
            'href' => "/groups/{$source->getKey()}/talk?m={$first->getKey()}",
            // The END of the stretch: an excerpt is read as where the conversation got to, and the
            // first issue ever reaches back a week, whose opening lines describe a room that has
            // since moved on.
            'messages' => $this->talk->excerpt($viewer, $this->talk->lastBetween($source, $since, $until)),
        ]);
    }

    /**
     * A newcomer, through the one-way block that governs every member-scoped page
     * ({@see MemberPolicy::access}) — 404-shaped there, and a face that is simply not
     * in the grid here.
     */
    private function newcomer(Member $viewer, HomeIssueItem $item, Model $source): ?HydratedItem
    {
        return $source instanceof Member && Gate::forUser($viewer)->allows('access', $source)
            ? new HydratedItem($item, $source)
            : null;
    }

    /**
     * An event whose day has passed stays: an issue is a snapshot of the morning it went out, and a
     * back issue that quietly shed its calendar as the week went by would misreport that morning.
     */
    private function calendarEntry(HomeIssueItem $item, Model $source): ?HydratedItem
    {
        return $source instanceof GroupEvent && $this->boardIsOpen($source)
            ? new HydratedItem($item, $source)
            : null;
    }

    /** Whether every signed-in member may read this group's boards — the group's own read column. */
    private function boardIsOpen(GroupTopic|GroupEvent $record): bool
    {
        return $record->group?->topic_read_access === TopicReadAccess::Everyone;
    }

    /**
     * A burst IS its window — the row holds no message id — so a row whose frozen stats carry none
     * describes nothing to re-resolve.
     *
     * @return array{CarbonImmutable, CarbonImmutable}|null
     */
    private function window(HomeIssueItem $item): ?array
    {
        $stats = $item->stats ?? [];
        $since = $stats['since'] ?? null;
        $until = $stats['until'] ?? null;

        if (! is_string($since) || ! is_string($until)) {
            return null;
        }

        return [CarbonImmutable::parse($since), CarbonImmutable::parse($until)];
    }
}
