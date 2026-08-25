<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Features\Diary\DiaryAccess;
use App\Features\GroupEvent\GroupEventAccess;
use App\Features\GroupTalk\GroupTalkAccess;
use App\Features\GroupTalk\GroupTalkController;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Features\Profile\ProfileAccess;
use App\Features\Timeline\TimelineAccess;
use App\Models\Diary;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\BodyFormat;
use App\Support\BodyRenderer;
use App\Support\BodyText;
use App\Support\ChatPreview;
use App\Support\Feature;
use App\Support\ViewerRelations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The kind of record one of this site's own URLs names.
 *
 * Closed for the reason {@see CardContext} is closed: the URL a member pasted decides which of these
 * is loaded, and a slug outside the list must resolve to nothing rather than to a model of the
 * asker's choosing. It is the *referent* — what the card is about — where CardContext is the
 * *referrer*, the body a card image is being requested through.
 *
 * Nothing here is stored on the card beyond the case and an id. What a card of a member's diary says
 * depends on who is reading it, and a card row is shared by every body that mentions the URL, so the
 * content is read from the record on every render — the rule a talk reply's quoted parent follows,
 * for the same reason: a copy taken at write time goes on showing what has since been retracted.
 *
 * **Two gates, and neither is restated here.** The unit has to be on — a card is reachable by URL
 * from a body, so an operator switching diaries off must take their previews with them, exactly as
 * `FilePolicy` resolves an owning feature for bytes. And the viewer has to be allowed to read the
 * record, which is each kind's own existing rule; a second implementation of "who may read this
 * topic" would drift, and silently.
 */
enum InternalCardTarget: string
{
    case Diary = 'diary';

    case Topic = 'topic';

    case Event = 'event';

    case TimelinePost = 'timeline';

    case Group = 'group';

    case Member = 'member';

    case TalkMessage = 'talk';

    /**
     * The unit this kind belongs to, or null for a member, whom no unit governs.
     *
     * `Feature::enabled()` resolves ancestors, so a topic's card stops when groups are switched off
     * as well.
     */
    public function feature(): ?Feature
    {
        return match ($this) {
            self::Diary => Feature::Diary,
            self::Topic => Feature::GroupTopic,
            self::Event => Feature::GroupEvent,
            self::TimelinePost => Feature::Timeline,
            self::Group => Feature::Group,
            self::TalkMessage => Feature::GroupTalk,
            self::Member => null,
        };
    }

    /**
     * The records, with what their access rule and their card both need to read.
     *
     * Plural because a page draws many cards at once and reading them one at a time is three queries
     * per row on the conversation poll; {@see InternalCardResolver} is what collects the ids.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Model>
     */
    public function findMany(array $ids): Collection
    {
        return match ($this) {
            self::Diary => Diary::with(['member', 'images.file'])->findMany($ids),
            self::Topic => GroupTopic::with(['group', 'images.file'])->findMany($ids),
            self::Event => GroupEvent::with(['group', 'images.file'])->findMany($ids),
            // The thread root comes with it: a reply is authorised by the thread it is in, not by
            // its own author — see canView.
            self::TimelinePost => TimelinePost::with(['member', 'parent.member', 'images.file'])->findMany($ids),
            self::Group => Group::with('image')->findMany($ids),
            self::Member => Member::with('avatar.file')->findMany($ids),
            self::TalkMessage => GroupMessage::with(['group', 'author', 'images.file'])->findMany($ids),
        };
    }

    /**
     * Read what $viewer is to the records this page's cards name, in one query per relation.
     *
     * Beside {@see canView()} deliberately: this is the same list of rules read in bulk, and written
     * apart the two drift the moment a rule changes what it asks. Nothing is decided here — the memo
     * only holds answers the rules go on to ask for ({@see ViewerRelations}), so a kind left out
     * costs queries rather than admitting anyone.
     *
     * A group's own page has no arm here: any signed-in member may read it, which asks nothing.
     *
     * @param  Collection<int, Model>  $records
     */
    public function warmRelations(Collection $records, Member $viewer): void
    {
        $relations = app(ViewerRelations::class);

        match ($this) {
            // The clearance rule asks both, and asks them of the author.
            self::Diary => $this->warmAudience($relations, $viewer, $records->pluck('member_id')->all()),
            // Of the thread root's author, never the reply's own — see canViewThread.
            self::TimelinePost => $this->warmAudience($relations, $viewer, $records->map(
                fn (Model $post): mixed => $post->in_reply_to_id === null ? $post->member_id : $post->parent?->member_id,
            )->all()),
            // MemberPolicy asks about the block and nothing else; a profile's own web-public switch
            // is a column already loaded.
            self::Member => $relations->warmBlocks($viewer, $records->modelKeys()),
            self::Topic, self::Event, self::TalkMessage => $relations->warmRoles($viewer, $records->pluck('group_id')->all()),
            // Asked whatever the group's read column says: reading the column here to skip the
            // groups that admit everyone would be a second copy of the board rule, and one query for
            // a page is not worth that.
            self::Group => null,
        };
    }

    /**
     * Whether $viewer may read $record, by that kind's own rule.
     *
     * Diaries, timeline posts and profiles can be web-public and take a nullable viewer; the group
     * bodies are members-only at the board level, so the null check is what answers a guest rather
     * than their rules failing on one. A group's own page is readable by any signed-in member — only
     * its boards carry a read gate — which is the rule `FilePolicy` applies to its top image.
     */
    public function canView(Model $record, ?Member $viewer): bool
    {
        return match ($this) {
            self::Diary => $record instanceof Diary && DiaryAccess::canView($viewer, $record),
            self::TimelinePost => $record instanceof TimelinePost && self::canViewThread($record, $viewer),
            self::Topic => $record instanceof GroupTopic && $viewer !== null && GroupTopicAccess::canViewTopic($record, $viewer),
            self::Event => $record instanceof GroupEvent && $viewer !== null && GroupEventAccess::canViewEvent($record, $viewer),
            self::TalkMessage => $record instanceof GroupMessage && $viewer !== null && GroupTalkAccess::canView($record->group, $viewer),
            self::Group => $record instanceof Group && $viewer !== null,
            self::Member => $record instanceof Member && ProfileAccess::canView($viewer, $record),
        };
    }

    /**
     * Whether the URL that resolved here actually leads to $record.
     *
     * Only talk carries a second address in its path. The conversation page refuses an anchor
     * naming another room's message ({@see GroupTalkController} scopes the
     * lookup to the route's group), so a card that answered for one would describe a message its
     * own URL does not open. Every other kind is named by its id alone.
     */
    public function urlLeadsTo(Model $record, InternalUrl $link): bool
    {
        return match ($this) {
            self::TalkMessage => $record instanceof GroupMessage && $record->group_id === $link->groupId,
            default => true,
        };
    }

    /**
     * What the card says, or null when there is nothing to draw.
     *
     * A title is the minimum, as it is for a fetched card ({@see LinkCard::isRenderable()}):
     * a picture alone is a mystery box, and a card with neither is worse than the bare link. The
     * description is the same excerpt a feed row shows, cut to {@see BodyText::EXCERPT_WIDTH}.
     *
     * @return array{title: string, description: string|null, image: File|null}|null
     */
    public function content(Model $record): ?array
    {
        $content = match ($this) {
            self::Diary => [
                'title' => (string) $record->title,
                'description' => BodyRenderer::excerpt($record->body, $record->format),
                'image' => $this->firstImage($record),
            ],
            self::Topic, self::Event => [
                'title' => (string) $record->name,
                'description' => BodyRenderer::excerpt($record->body, $record->format),
                'image' => $this->firstImage($record),
            ],
            // No format column on a timeline post; its body is plain by construction.
            self::TimelinePost => [
                'title' => __('%post_activity% by :name', ['name' => $record->member->name]),
                'description' => BodyRenderer::excerpt($record->body, BodyFormat::Plain),
                // A reply's picture belongs to the reply, and FilePolicy asks the row that owns the
                // bytes — the replier's own rule, not the thread's, which is what admitted this card.
                // So a reader the replier has blocked gets a card whose picture 404s. That is exactly
                // what the thread page does with the same reply's picture, and a rule written here to
                // differ would be a second answer for one file.
                'image' => $this->firstImage($record),
            ],
            self::Group => [
                'title' => (string) $record->name,
                // Not a stored body: a group's description is free text with no format of its own,
                // and this is the cut every Classic list gives such a value.
                'description' => BodyText::truncateToRows($record->description),
                'image' => $record->image,
            ],
            self::Member => [
                'title' => (string) $record->name,
                // A profile's fields each carry their own visibility, and choosing one to preview
                // here would mean re-deriving that per field. The name and the face are what a
                // reference to a member says everywhere else in the app.
                'description' => null,
                'image' => $record->avatar?->file,
            ],
            self::TalkMessage => [
                'title' => __('Message from :name', ['name' => $record->author?->name ?? __('Withdrawn member')]),
                // The same line every list previews a message by, so a card of a message and the
                // room row above it read alike.
                'description' => ChatPreview::lineOrImages([$record->body], $record->images->isNotEmpty()),
                'image' => $this->firstImage($record),
            ],
        };

        if ($content['title'] === '') {
            return null;
        }

        // An empty excerpt is no description rather than an empty one, so neither surface draws a
        // blank line under the title.
        $content['description'] = $content['description'] === '' ? null : $content['description'];

        return $content;
    }

    /**
     * The pair of relations `Visibility::clearanceFor` and the block test between them ask about an
     * owner.
     *
     * @param  list<int|string|null>  $ownerIds
     */
    private function warmAudience(ViewerRelations $relations, Member $viewer, array $ownerIds): void
    {
        $relations->warmBlocks($viewer, $ownerIds);
        $relations->warmFriends($viewer, $ownerIds);
    }

    /** The picture a body leads with, or null when it has none. */
    private function firstImage(Model $record): ?File
    {
        return $record->images->first()?->file;
    }

    /**
     * Whether $viewer may read the thread $post is in.
     *
     * The root, never the row itself, when the row is a reply: a reply inherits the root's visibility
     * but carries its own author, and `TimelineAccess` reads the row's author — so the reply's own
     * rule admits the *replier's* friends, who are not who the thread was gated for. A reply whose
     * root is gone is refused rather than falling back to its own rule; the same call and the same
     * reasoning as {@see CardContext}.
     */
    private static function canViewThread(TimelinePost $post, ?Member $viewer): bool
    {
        if ($post->in_reply_to_id !== null) {
            return $post->parent !== null && TimelineAccess::canView($viewer, $post->parent);
        }

        return TimelineAccess::canView($viewer, $post);
    }
}
