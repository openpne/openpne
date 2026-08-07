<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\Diary\DiaryAccess;
use App\Features\Timeline\TimelineAccess;
use App\Models\CommunityEvent;
use App\Models\CommunityTopic;
use App\Models\Diary;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;
use Illuminate\Database\Eloquent\Model;

/**
 * The kind of body a link-card image is being requested through.
 *
 * A card image is shared: the same picture can sit under a world-readable diary and a private one at
 * the same time, so the File itself cannot carry a visibility. What decides whether a viewer may see
 * it is *which post they are looking at*, and this enum is how that post is named in the URL.
 *
 * It is a closed enum for exactly that reason. The alternative — putting a class name or a morph
 * type in the URL — would let a request choose which model the app resolves, which is a much larger
 * hole than the one being closed. A slug outside this list simply does not resolve.
 *
 * `canView` delegates to each kind's existing access rule rather than restating it. Visibility here
 * has to mean the same thing it means on the post: the same blocks, the same community membership,
 * the same web-public rule. A second implementation would drift, and the drift would be silent.
 */
enum CardContext: string
{
    case Diary = 'diary';

    case Topic = 'topic';

    case Event = 'event';

    case TimelinePost = 'timeline';

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
            CommunityTopic::class => self::Topic,
            CommunityEvent::class => self::Event,
            TimelinePost::class => self::TimelinePost,
            default => null,
        };
    }

    /**
     * The unit this kind of post belongs to.
     *
     * A card image is fetched by URL, so no page mediates it — the same reason `FilePolicy` resolves
     * an owning feature. Without this a known image URL keeps returning bytes after an operator
     * switches the module off, while every screen around it is gone. `Feature::enabled()` resolves
     * ancestors, so a topic's card stops when communities are switched off as well.
     */
    public function feature(): Feature
    {
        return match ($this) {
            self::Diary => Feature::Diary,
            self::Topic => Feature::CommunityTopic,
            self::Event => Feature::CommunityEvent,
            self::TimelinePost => Feature::Timeline,
        };
    }

    /**
     * The URL of $record's card image at the given size, or null when there is no image to show.
     *
     * Built here rather than at each call site so the same File never gets a context-free URL by
     * accident: what makes this safe is that the address names the post, and a caller reaching for
     * `File::url()` instead would produce a link the generic file route refuses — or, worse, one
     * that authorised the wrong thing.
     */
    public static function imageUrl(Model $record, int $width, int $height, bool $square = false): ?string
    {
        $kind = self::forRecord($record);
        $card = $record->getAttribute('link_card_id') === null ? null : $record->linkCard;

        if ($kind === null || ! $kind->carriesCard($record) || ! $card instanceof LinkCard || ! $card->isRenderable()) {
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
            self::Topic => CommunityTopic::with(['community', 'linkCard'])->find($id),
            self::Event => CommunityEvent::with(['community', 'linkCard'])->find($id),
            // Replies are excluded in the query, not filtered after: a reply id must not resolve at
            // all, so nothing downstream can authorize against it. See carriesCard.
            self::TimelinePost => TimelinePost::with(['member', 'linkCard'])->whereNull('in_reply_to_id')->find($id),
        };
    }

    /**
     * Whether $record is the kind of row that may carry a card at all.
     *
     * Timeline replies are deliberately never synced — they render as a thread, where a stack of
     * cards reads as noise — so today no reply row has a `link_card_id` to serve. This does not rely
     * on that: a permalink to a reply re-centers to its thread root and is authorised as the root
     * (`ShowTimelinePost`), while `TimelineAccess::canView` given the reply would answer for the
     * reply's own author and visibility. A card URL naming a reply would therefore ask a different
     * audience than the page it appears on, so it is refused rather than answered.
     */
    private function carriesCard(Model $record): bool
    {
        return ! ($record instanceof TimelinePost) || $record->in_reply_to_id === null;
    }

    /**
     * Whether $viewer may read $record, by that kind's own rule.
     *
     * Diary and timeline posts can be web-public, so they take a nullable viewer; the community
     * bodies are members-only at the board level and have no signed-out case to express.
     */
    public function canView(Model $record, ?Member $viewer): bool
    {
        return match ($this) {
            self::Diary => $record instanceof Diary && DiaryAccess::canView($viewer, $record),
            self::TimelinePost => $record instanceof TimelinePost && TimelineAccess::canView($viewer, $record),
            self::Topic => $record instanceof CommunityTopic && $viewer !== null && CommunityTopicAccess::canViewTopic($record, $viewer),
            self::Event => $record instanceof CommunityEvent && $viewer !== null && CommunityEventAccess::canViewEvent($record, $viewer),
        };
    }
}
