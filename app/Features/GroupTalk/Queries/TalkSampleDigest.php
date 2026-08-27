<?php

declare(strict_types=1);

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\GroupTalkAccess;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Summarizing a stretch of a group's talk: a bounded read of the stretch, and the summaries drawn
 * from the rows that read returned — who did the talking, and a glimpse of what was posted.
 *
 * Bounded by contract, and the bound is visible in what a summary says: however long the stretch,
 * the faces and the pictures describe its first {@see SAMPLE} messages rather than an aggregate over
 * the lot. How much was said is therefore a query of its own ({@see countBetween}) — a bounded read
 * cannot say a number it is bounded away from.
 *
 * The window reads apply no per-row viewer filter, because talk applies none: the whole conversation
 * is one audience, for the reasons in {@see GroupTalkAccess}. Pictures are the exception, and are
 * gated per file in {@see thumbnails}.
 */
final class TalkSampleDigest
{
    /**
     * How many messages a stretch is described FROM, however many it holds. Summarizing an unbounded
     * stretch would read the whole of a room nobody has opened for a month.
     */
    public const SAMPLE = 50;

    /** Faces a summary names. */
    public const PARTICIPANTS = 5;

    /** Pictures a summary shows. */
    public const THUMBNAILS = 3;

    /**
     * Attachment rows read as thumbnail candidates. The sample bounds the parents, but a parent does
     * not bound its attachments (a migrated message may carry any number), so the pictures get a cap
     * of their own — with headroom past {@see THUMBNAILS} so a refused candidate can be refilled. If
     * every candidate is refused, fewer than three are shown: bounded by contract, not refilled from
     * an unbounded read.
     */
    public const THUMBNAIL_CANDIDATES = 12;

    /**
     * The window's first $limit messages, oldest first.
     *
     * Both edges are inclusive: a period is named by the instants at its ends, and a message written
     * exactly on one of them belongs to the period that names it.
     *
     * @return Collection<int, GroupMessage>
     */
    public function sampleBetween(Group $group, CarbonImmutable $since, CarbonImmutable $until, int $limit = self::SAMPLE): Collection
    {
        return $this->window($group, $since, $until)
            // A summary draws a face per author; without this the sample would cost a query per row
            // it summarizes. Pictures are NOT loaded here — a parent does not bound its attachment
            // count, so they get their own capped read in thumbnails().
            ->with('author.avatar.file')
            ->limit($limit)
            ->get();
    }

    /** How much was said in the window — all of it, which is the number a bounded sample cannot say. */
    public function countBetween(Group $group, CarbonImmutable $since, CarbonImmutable $until): int
    {
        return $this->window($group, $since, $until)->count();
    }

    /**
     * The window's first message, or null when nothing is left in it — a stretch whose messages have
     * all been deleted has nothing to anchor on, and a caller has to be able to see that.
     *
     * Its own read, so a caller wanting only the anchor does not pay for the sample.
     */
    public function firstBetween(Group $group, CarbonImmutable $since, CarbonImmutable $until): ?GroupMessage
    {
        return $this->window($group, $since, $until)->first();
    }

    /**
     * Who did the talking, busiest first, ties broken by who spoke first — grouped here rather than
     * in SQL because the rows are already in hand and a GROUP BY would have to read past the sample
     * to be worth its own query.
     *
     * A withdrawn author is skipped: there is no member to draw a face for, and a blank one in a row
     * of faces reads as somebody rather than as nobody.
     *
     * @param  Collection<int, GroupMessage>  $sample
     * @return list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}>
     */
    public function participants(Collection $sample): array
    {
        /** @var array<int, array{author: Member, said: int}> $spoke keyed by member id, in first-appearance order */
        $spoke = [];

        foreach ($sample as $message) {
            $author = $message->author;

            if ($author === null) {
                continue;
            }

            $id = (int) $author->getKey();
            $spoke[$id] ??= ['author' => $author, 'said' => 0];
            $spoke[$id]['said']++;
        }

        // PHP's sorts have been stable since 8.0, so equal counts keep the insertion order above —
        // which is first appearance. That is the tie-break, not an accident of it.
        uasort($spoke, fn (array $a, array $b): int => $b['said'] <=> $a['said']);

        return array_map(
            fn (array $entry): array => MemberRefSerializer::ref($entry['author']),
            array_slice(array_values($spoke), 0, self::PARTICIPANTS),
        );
    }

    /**
     * A glimpse of what was posted: the first {@see THUMBNAILS} pictures of the sample, in message
     * order and slot order within a message.
     *
     * Every candidate passes two gates. The join row names a file, but only the file names its owner,
     * so a row whose file belongs to some other parent is refused as not this message's picture —
     * it might well pass the policy on its own owner's terms. Then the policy that guards the bytes
     * (FilePolicy) is asked per file, the same one the delivery route asks. A refusal, or a file that
     * is no longer there, is skipped in silence: no placeholder, no gap, nothing in the payload
     * saying a picture was left out.
     *
     * The read is bounded on its own terms: the first {@see THUMBNAIL_CANDIDATES} attachment rows of
     * the sampled messages, in the stream's own (created_at, id, slot) order, with the file
     * eager-loaded once. The gates then run over those candidates only, so neither the row count nor
     * the policy calls can grow with how many pictures a message carries.
     *
     * @param  Collection<int, GroupMessage>  $sample
     * @return list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}>
     */
    public function thumbnails(Member $viewer, Collection $sample): array
    {
        if ($sample->isEmpty()) {
            return [];
        }

        $parents = $sample->keyBy(fn (GroupMessage $message): int => (int) $message->getKey());

        // Ordered by the parent's (created_at, id) — talk's total order (UnreadTalkScope), which a
        // migrated room's ids do not necessarily follow — so "the first pictures of the sample"
        // means the same thing here as it does in the stream. The join is bounded by the sampled ids.
        $candidates = GroupMessageImage::query()
            ->join('group_messages', 'group_messages.id', '=', 'group_message_images.group_message_id')
            ->whereIn('group_message_images.group_message_id', $parents->keys())
            ->with('file')
            ->orderBy('group_messages.created_at')
            ->orderBy('group_messages.id')
            ->orderBy('group_message_images.number')
            ->limit(self::THUMBNAIL_CANDIDATES)
            ->select('group_message_images.*')
            ->get();

        $shown = [];

        foreach ($candidates as $image) {
            /** @var GroupMessage $message */
            $message = $parents[(int) $image->group_message_id];
            $file = $image->file;

            if ($file === null || (int) $file->related_entity_id !== (int) $message->getKey()) {
                continue;
            }

            // instanceof rather than a string match, so the legacy morph aliases resolve too.
            $ownerClass = Relation::getMorphedModel($file->related_entity_type ?? '');
            if ($ownerClass === null || ! $message instanceof $ownerClass) {
                continue;
            }

            if (! Gate::forUser($viewer)->allows('view', $file)) {
                continue;
            }

            $shown[] = GroupMessageSerializer::image($image);

            if (count($shown) === self::THUMBNAILS) {
                return $shown;
            }
        }

        return $shown;
    }

    /**
     * The window itself, in talk's total order — one place, so the sample, the count and the anchor
     * can only ever describe the same stretch.
     *
     * @return Builder<GroupMessage>
     */
    private function window(Group $group, CarbonImmutable $since, CarbonImmutable $until): Builder
    {
        return GroupMessage::query()
            ->where('group_id', $group->getKey())
            ->whereBetween('group_messages.created_at', [$since, $until])
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
