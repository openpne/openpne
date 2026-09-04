<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Features\Diary\DiaryAccess;
use App\Features\GroupEvent\GroupEventAccess;
use App\Features\GroupTalk\GroupTalkAccess;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Features\Timeline\TimelineAccess;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;
use Illuminate\Database\Eloquent\Model;

/**
 * The post a card image is requested through, named in the URL: a card is shared, so the File
 * cannot carry a visibility and only the post being looked at can answer. A closed enum, because
 * the URL may choose which post is consulted and never which model the app resolves; `canView`
 * delegates to each kind's own access rule.
 */
enum CardContext: string
{
    case Diary = 'diary';

    case Topic = 'topic';

    case Event = 'event';

    case TimelinePost = 'timeline';

    case Talk = 'talk';

    case DiaryComment = 'diaryComment';

    case TopicComment = 'topicComment';

    case EventComment = 'eventComment';

    /** The context named by a URL segment, or null when the segment is not one of these. */
    public static function fromSlug(string $slug): ?self
    {
        return self::tryFrom($slug);
    }

    /** The slug naming $record's kind, or null for a model that cannot carry a card. */
    public static function forRecord(Model $record): ?self
    {
        return match ($record::class) {
            Diary::class => self::Diary,
            GroupTopic::class => self::Topic,
            GroupEvent::class => self::Event,
            TimelinePost::class => self::TimelinePost,
            GroupMessage::class => self::Talk,
            DiaryComment::class => self::DiaryComment,
            GroupTopicComment::class => self::TopicComment,
            GroupEventComment::class => self::EventComment,
            default => null,
        };
    }

    /**
     * The one place that knows every table with a `link_card_id` column, so the prune sweep cannot
     * be left behind when a kind is added. A table name and nothing more: a filter carried over from
     * {@see find()} would make the sweep delete a card a row it refuses still uses, and that loss is
     * permanent because `link_card_synced_at` stays set.
     */
    public function table(): string
    {
        return match ($this) {
            self::Diary => (new Diary)->getTable(),
            self::Topic => (new GroupTopic)->getTable(),
            self::Event => (new GroupEvent)->getTable(),
            self::TimelinePost => (new TimelinePost)->getTable(),
            self::Talk => (new GroupMessage)->getTable(),
            self::DiaryComment => (new DiaryComment)->getTable(),
            self::TopicComment => (new GroupTopicComment)->getTable(),
            self::EventComment => (new GroupEventComment)->getTable(),
        };
    }

    /**
     * The unit this kind of post belongs to, resolved because a card image is fetched by URL with
     * no page to mediate it, so switching the module off has to stop its images too.
     * `Feature::enabled()` resolves ancestors, so a topic's card stops when groups are switched off
     * as well.
     */
    public function feature(): Feature
    {
        return match ($this) {
            self::Diary => Feature::Diary,
            self::Topic => Feature::GroupTopic,
            self::Event => Feature::GroupEvent,
            self::TimelinePost => Feature::Timeline,
            self::Talk => Feature::GroupTalk,
            // A comment belongs to the unit its body does: switching diaries off takes their
            // comments' pictures with them, as it takes the diaries'.
            self::DiaryComment => Feature::Diary,
            self::TopicComment => Feature::GroupTopic,
            self::EventComment => Feature::GroupEvent,
        };
    }

    /**
     * The URL of $record's card image at the given size, or null when there is no image to show.
     * Built here so the same File never gets a context-free URL: `File::url()` would produce a link
     * the generic file route refuses, or one that authorises the wrong thing.
     */
    public static function imageUrl(Model $record, int $width, int $height, bool $square = false): ?string
    {
        $kind = self::forRecord($record);
        $card = $record->getAttribute('link_card_id') === null ? null : $record->linkCard;

        if ($kind === null || ! $card instanceof LinkCard || ! $card->isRenderable()) {
            return null;
        }

        $file = $card->image;
        $format = $file?->imageFormat();

        if ($file === null || $format === null) {
            return null;
        }

        return route('linkCard.image', [
            'context' => $kind->value,
            'record' => $record->getKey(),
            'format' => $format,
            'geometry' => "w{$width}_h{$height}".($square ? '_sq' : ''),
            'name' => $file->name,
            'ext' => $format,
        ]);
    }

    /**
     * Load the record, with what its access rule needs to decide.
     *
     * The card is loaded too: every check downstream is about this record's *current* card, so a
     * URL that outlived an edit has to fail on fresh data rather than on anything the URL asserts.
     */
    public function find(int $id): ?Model
    {
        return match ($this) {
            self::Diary => Diary::with(['member', 'linkCard'])->find($id),
            self::Topic => GroupTopic::with(['group', 'linkCard'])->find($id),
            self::Event => GroupEvent::with(['group', 'linkCard'])->find($id),
            // A reply resolves and is authorised by its thread, so its root comes with it (see canView).
            self::TimelinePost => TimelinePost::with(['member', 'parent.member', 'linkCard'])->find($id),
            // Not scoped to a group: which group a message belongs to is the message's own fact, and
            // canView refuses another room's message by that room's rule, as the conversation page would.
            self::Talk => GroupMessage::with(['group', 'linkCard'])->find($id),
            // The body a comment hangs under comes with it: that is whose rule decides, so it is
            // loaded with what that rule reads.
            self::DiaryComment => DiaryComment::with(['diary.member', 'linkCard'])->find($id),
            self::TopicComment => GroupTopicComment::with(['topic.group', 'linkCard'])->find($id),
            self::EventComment => GroupEventComment::with(['event.group', 'linkCard'])->find($id),
        };
    }

    /**
     * The thread's root decides for a reply: a reply inherits the root's visibility but carries its
     * own author, and `TimelineAccess` reads the row's author, so the reply's own rule would admit
     * the replier's friends, who are not who the page was gated for. A reply whose root is gone is
     * refused rather than judged on its own rule.
     */
    private static function canViewThread(TimelinePost $post, ?Member $viewer): bool
    {
        if ($post->in_reply_to_id !== null) {
            return $post->parent !== null && TimelineAccess::canView($viewer, $post->parent);
        }

        return TimelineAccess::canView($viewer, $post);
    }

    /**
     * Whether $viewer may read $record, by that kind's own rule.
     *
     * Diary and timeline posts can be web-public, so they take a nullable viewer; the group bodies
     * are members-only at the board level and have no signed-out case to express — their rules take
     * a Member, so the null check is what answers a guest rather than failing on one.
     */
    public function canView(Model $record, ?Member $viewer): bool
    {
        return match ($this) {
            self::Diary => $record instanceof Diary && DiaryAccess::canView($viewer, $record),
            self::TimelinePost => $record instanceof TimelinePost && self::canViewThread($record, $viewer),
            self::Topic => $record instanceof GroupTopic && $viewer !== null && GroupTopicAccess::canViewTopic($record, $viewer),
            self::Event => $record instanceof GroupEvent && $viewer !== null && GroupEventAccess::canViewEvent($record, $viewer),
            self::Talk => $record instanceof GroupMessage && $viewer !== null && GroupTalkAccess::canView($record->group, $viewer),
            // No missing-parent branch: the column is NOT NULL and cascades, and none of the three
            // bodies is soft-deleted, so a comment cannot resolve without its body; give one of them
            // SoftDeletes and this needs the reply's refusal.
            self::DiaryComment => $record instanceof DiaryComment && DiaryAccess::canView($viewer, $record->diary),
            self::TopicComment => $record instanceof GroupTopicComment && $viewer !== null && GroupTopicAccess::canViewTopic($record->topic, $viewer),
            self::EventComment => $record instanceof GroupEventComment && $viewer !== null && GroupEventAccess::canViewEvent($record->event, $viewer),
        };
    }
}
