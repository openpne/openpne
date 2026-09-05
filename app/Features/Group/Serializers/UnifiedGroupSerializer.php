<?php

namespace App\Features\Group\Serializers;

use App\Features\Group\GroupRole;
use App\Features\Group\Queries\CategoryGroups;
use App\Features\GroupEvent\Serializers\GroupEventSerializer;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Features\GroupTopic\Serializers\GroupTopicSerializer;
use App\Features\Home\Serializers\UnifiedSections;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\Member;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Everything arrives already decided: the controller resolves the viewer's role, the read gates and
 * the rows behind them once for both layouts. The two additions this layout brings — the
 * same-category groups and the picture strip — are gathered here, each behind a gate it answered.
 */
final class UnifiedGroupSerializer
{
    /**
     * @param  Collection<int, GroupMember>  $members  the 9-face preview, `member.avatar.file` loaded
     * @param  Collection<int, GroupTopic>|null  $recentTopics  null = the viewer may not read the boards
     * @param  Collection<int, GroupEvent>|null  $recentEvents
     * @param  array{body: string, authorName: string|null, createdAt: string}|null  $talkPreview
     * @return array{group: array{id: int, name: string, avatarUrl: string|null, avatarUrlLarge: string|null, avatarColor: null, isAi: false, bio: string|null, memberCount: int, categoryName: string|null, registerPolicy: string}, viewerRole: string|null, isPending: bool, isTransferNominee: bool, canManage: bool, canJoin: bool, canLeave: bool, members: list<array>, categoryGroups: list<array>, recentTopics: list<array>|null, canPostTopic: bool, recentEvents: list<array>|null, canPostEvent: bool, canViewTalk: bool, talkPreview: array|null, talkUnread: int, recentPhotos: list<array>}
     */
    public static function page(
        Member $viewer,
        Group $group,
        ?GroupRole $role,
        bool $isPending,
        bool $isTransferNominee,
        bool $canManage,
        bool $canJoin,
        bool $canLeave,
        Collection $members,
        ?Collection $recentTopics,
        bool $canPostTopic,
        ?Collection $recentEvents,
        bool $canPostEvent,
        bool $canViewTalk,
        ?array $talkPreview,
        int $talkUnread,
    ): array {
        return [
            'group' => self::identity($group),
            'viewerRole' => $role?->slug(),
            'isPending' => $isPending,
            'isTransferNominee' => $isTransferNominee,
            'canManage' => $canManage,
            'canJoin' => $canJoin,
            'canLeave' => $canLeave,
            'members' => UnifiedSections::people($members->pluck('member')),
            'categoryGroups' => self::categoryGroups($group),
            'recentTopics' => $recentTopics === null ? null : GroupTopicSerializer::summaries($recentTopics),
            'canPostTopic' => $canPostTopic,
            'recentEvents' => $recentEvents === null ? null : GroupEventSerializer::summaries($recentEvents),
            'canPostEvent' => $canPostEvent,
            'canViewTalk' => $canViewTalk,
            'talkPreview' => $talkPreview,
            'talkUnread' => $talkUnread,
            // The board collections are null exactly when their read gate refused, so they are the
            // gate the picture sources ask as well — a source whose rows may not be read is not read.
            'recentPhotos' => self::photos($viewer, $group, $canViewTalk, $recentTopics !== null, $recentEvents !== null),
        ];
    }

    /**
     * avatarColor / isAi are the shared header's member fields, which a group carries nothing in —
     * the initial badge stands in when there is no cover.
     *
     * @return array{id: int, name: string, avatarUrl: string|null, avatarUrlLarge: string|null, avatarColor: null, isAi: false, bio: string|null, memberCount: int, categoryName: string|null, registerPolicy: string}
     */
    private static function identity(Group $group): array
    {
        $file = $group->image;

        return [
            'id' => $group->getKey(),
            'name' => $group->name,
            'avatarUrl' => $file?->thumbnailUrl(640, 640, square: true),
            'avatarUrlLarge' => $file?->thumbnailUrl(1200, 1200, square: true),
            'avatarColor' => null,
            'isAi' => false,
            'bio' => $group->description,
            'memberCount' => $group->members_count ?? $group->loadCount('members')->members_count,
            'categoryName' => $group->category?->name,
            'registerPolicy' => $group->register_policy->slug(),
        ];
    }

    /**
     * @return list<array{id: int, name: string, imageUrl: string|null, href: string, memberCount: int}>
     */
    private static function categoryGroups(Group $group): array
    {
        return (new CategoryGroups)->take($group)->map(fn (Group $related): array => [
            'id' => $related->getKey(),
            'name' => $related->name,
            'imageUrl' => $related->image?->thumbnailUrl(320, 320, square: true),
            'href' => "/groups/{$related->getKey()}",
            'memberCount' => $related->members_count,
        ])->values()->all();
    }

    /**
     * Each source is read only behind the gate its own screen is behind.
     *
     * @return list<array{source: string, href: string, image: array}>
     */
    private static function photos(Member $viewer, Group $group, bool $talkReadable, bool $topicsReadable, bool $eventsReadable): array
    {
        $groupId = $group->getKey();
        $parents = [];

        if ($talkReadable) {
            foreach (self::photoParents($group->messages()) as $message) {
                $parents[] = [
                    'source' => 'talk',
                    'at' => $message->created_at,
                    'id' => $message->getKey(),
                    // The talk screen's own deep link, so the tile lands on the message rather than
                    // at the foot of the conversation.
                    'href' => "/groups/{$groupId}/talk?m={$message->getKey()}",
                    'images' => self::readableImages($viewer, $message, $message->images, GroupMessageSerializer::image(...)),
                ];
            }
        }

        if ($topicsReadable) {
            foreach (self::photoParents($group->topics()) as $topic) {
                $parents[] = [
                    'source' => 'topic',
                    'at' => $topic->created_at,
                    'id' => $topic->getKey(),
                    'href' => "/topics/{$topic->getKey()}",
                    'images' => self::readableImages($viewer, $topic, $topic->images, GroupTopicSerializer::image(...)),
                ];
            }
        }

        if ($eventsReadable) {
            foreach (self::photoParents($group->events()) as $event) {
                $parents[] = [
                    'source' => 'event',
                    'at' => $event->created_at,
                    'id' => $event->getKey(),
                    'href' => "/events/{$event->getKey()}",
                    'images' => self::readableImages($viewer, $event, $event->images, GroupEventSerializer::image(...)),
                ];
            }
        }

        return UnifiedSections::photosFromParents($parents);
    }

    /**
     * Ordered by when it was posted, not when it was last touched — a comment on an old topic does
     * not make its pictures new.
     *
     * @param  HasMany<GroupMessage|GroupTopic|GroupEvent, Group>  $parents
     * @return Collection<int, GroupMessage|GroupTopic|GroupEvent>
     */
    private static function photoParents(HasMany $parents): Collection
    {
        return $parents->whereHas('images')
            ->with('images.file')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(UnifiedSections::PHOTOS)
            ->get();
    }

    /**
     * Every candidate is asked again here, per file, through the policy that guards the bytes: one
     * that refuses or is gone is left out in silence, with nothing in the payload saying so. Lazy on
     * purpose — photosFromParents walks this only as far as the cap reaches.
     *
     * @param  Collection<int, Model>  $images
     * @param  callable(mixed): array  $shape
     * @return Generator<int, array>
     */
    private static function readableImages(Member $viewer, Model $parent, Collection $images, callable $shape): Generator
    {
        foreach ($images as $image) {
            $file = $image->file;

            if ($file === null || $file->related_entity_id !== $parent->getKey()) {
                continue;
            }
            // FilePolicy authorizes against the owner the file declares, so a file belonging elsewhere
            // could pass the Gate on that owner's terms; instanceof absorbs the legacy morph aliases.
            $ownerClass = Relation::getMorphedModel($file->related_entity_type ?? '');
            if ($ownerClass === null || ! $parent instanceof $ownerClass) {
                continue;
            }

            if (Gate::forUser($viewer)->allows('view', $file)) {
                yield $shape($image);
            }
        }
    }
}
