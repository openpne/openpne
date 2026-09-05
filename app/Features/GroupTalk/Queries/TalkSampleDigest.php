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
 * Bounded by contract: however long the stretch, a summary describes its first {@see SAMPLE} messages
 * rather than the lot, so how much was said is a query of its own ({@see countBetween}). The window
 * reads apply no per-row viewer filter, as talk applies none ({@see GroupTalkAccess}); pictures are
 * the exception, gated per file in {@see shows}.
 */
final class TalkSampleDigest
{
    /** Summarizing an unbounded stretch would read the whole of a room nobody has opened for a month. */
    public const SAMPLE = 50;

    public const PARTICIPANTS = 5;

    public const THUMBNAILS = 3;

    /**
     * A parent does not bound its attachments — a migrated message may carry any number — so the
     * candidates get a cap of their own, with headroom past {@see THUMBNAILS} to refill a refused
     * one. If every candidate is refused, fewer are shown rather than the read reaching further down.
     */
    public const THUMBNAIL_CANDIDATES = 12;

    public const EXCERPT = 6;

    public const EXCERPT_PICTURES = 3;

    /** @return Collection<int, GroupMessage> */
    public function sampleBetween(Group $group, CarbonImmutable $since, CarbonImmutable $until, int $limit = self::SAMPLE): Collection
    {
        return $this->window($group, $since, $until)
            // Pictures are deliberately not loaded here: a parent does not bound its attachment
            // count, so they get their own capped read in `thumbnails()`.
            ->with('author.avatar.file')
            ->limit($limit)
            ->get();
    }

    /**
     * The tail rather than the head, because an excerpt reads as where the conversation got to.
     * Pictures come with the rows and are still gated per file — loading a row is not showing it
     * ({@see imagesOf}).
     *
     * @return Collection<int, GroupMessage>
     */
    public function lastBetween(Group $group, CarbonImmutable $since, CarbonImmutable $until, int $limit = self::EXCERPT): Collection
    {
        return $this->window($group, $since, $until)
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->with(['author.avatar.file', 'images.file', 'mentions'])
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * The stream's own shape minus what belongs to a live room — no cursor, no reactions, no
     * permissions — so nothing here is a claim about what the reader may do.
     *
     * @param  Collection<int, GroupMessage>  $messages
     * @return list<array{id: int, author: array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}|null, body: string, mentions: list<array{memberId: int, offset: int, length: int}>, createdAt: string, images: list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}>}>
     */
    public function excerpt(Member $viewer, Collection $messages): array
    {
        return $messages
            ->map(fn (GroupMessage $message): array => [
                'id' => (int) $message->getKey(),
                'author' => $message->author === null ? null : MemberRefSerializer::ref($message->author),
                'body' => (string) $message->body,
                'mentions' => GroupMessageSerializer::mentions($message),
                'createdAt' => GroupMessageSerializer::instant($message->created_at),
                'images' => $this->imagesOf($viewer, $message),
            ])
            ->values()
            ->all();
    }

    /**
     * The cap is on the slots looked at, not on the pictures shown: a message whose first three are
     * refused shows none rather than reaching further down for replacements.
     *
     * @return list<array{id: int, url: string, thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null}>
     */
    public function imagesOf(Member $viewer, GroupMessage $message): array
    {
        return $message->images
            ->take(self::EXCERPT_PICTURES)
            ->filter(fn (GroupMessageImage $image): bool => $this->shows($viewer, $message, $image))
            ->map(fn (GroupMessageImage $image): array => GroupMessageSerializer::image($image))
            ->values()
            ->all();
    }

    public function countBetween(Group $group, CarbonImmutable $since, CarbonImmutable $until): int
    {
        return $this->window($group, $since, $until)->count();
    }

    /** Null when every message in the stretch has been deleted, which a caller has to be able to see. */
    public function firstBetween(Group $group, CarbonImmutable $since, CarbonImmutable $until): ?GroupMessage
    {
        return $this->window($group, $since, $until)->first();
    }

    /**
     * Busiest first, ties broken by who spoke first. A withdrawn author is skipped rather than drawn
     * as a blank face.
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

        // PHP's sorts have been stable since 8.0, so equal counts keep the insertion order — first
        // appearance — which is the tie-break rather than an accident of it.
        uasort($spoke, fn (array $a, array $b): int => $b['said'] <=> $a['said']);

        return array_map(
            fn (array $entry): array => MemberRefSerializer::ref($entry['author']),
            array_slice(array_values($spoke), 0, self::PARTICIPANTS),
        );
    }

    /**
     * The gates run over the capped candidate rows only, so neither the row count nor the policy
     * calls grow with how many pictures a message carries.
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

        // Ordered by the parent's `(created_at, id)`, which a migrated room's ids do not follow, so
        // "the first pictures of the sample" means the same here as it does in the stream.
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

            if (! $this->shows($viewer, $message, $image)) {
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
     * Only the file names its owner, so a row whose file belongs to another parent is refused as not
     * this message's picture — it might well pass the policy on its own owner's terms. A refusal
     * leaves no trace in the payload.
     */
    private function shows(Member $viewer, GroupMessage $message, GroupMessageImage $image): bool
    {
        $file = $image->file;

        if ($file === null || (int) $file->related_entity_id !== (int) $message->getKey()) {
            return false;
        }

        // instanceof rather than a string match, so the legacy morph aliases resolve too.
        $ownerClass = Relation::getMorphedModel($file->related_entity_type ?? '');
        if ($ownerClass === null || ! $message instanceof $ownerClass) {
            return false;
        }

        return Gate::forUser($viewer)->allows('view', $file);
    }

    /**
     * The window is `(since, until]`, so a message written exactly on the instant two consecutive
     * windows share belongs to the one that closes on it. One place, so the sample, the count and
     * the anchor can only describe the same stretch.
     *
     * @return Builder<GroupMessage>
     */
    private function window(Group $group, CarbonImmutable $since, CarbonImmutable $until): Builder
    {
        return GroupMessage::query()
            ->where('group_id', $group->getKey())
            ->where('group_messages.created_at', '>', $since)
            ->where('group_messages.created_at', '<=', $until)
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
