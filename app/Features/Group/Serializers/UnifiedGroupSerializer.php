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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The unified group page (SnsSettingKey::ModernUnifiedHome): the same grammar as the unified home and
 * member page, about a group — who it is, who is in it, what has been happening in it.
 *
 * Everything the shipped group page can do arrives here already decided: the controller resolves the
 * viewer's role, the read gates, and the rows behind them once for both layouts, and this shapes what
 * it is handed. The two additions the layout brings — the same-category groups and the picture strip —
 * are gathered here, each behind a gate the controller has already answered.
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
            // The member preview as a row of faces, admins first — the order the controller queried.
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
     * The hero's identity block, in the shape the shared header paints: the group's cover as the two
     * `srcset` rungs, its description as the body. avatarColor/isAi are the header's member fields,
     * which a group carries nothing in — the initial badge stands in when there is no cover.
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
            // Drives the join button's label: applying is not joining.
            'registerPolicy' => $group->register_policy->slug(),
        ];
    }

    /**
     * The cover cards of the groups filed under the same category (CategoryGroups), each captioned
     * with its size — what someone choosing between them is asking. The shared card plus that count.
     *
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
     * The group's latest pictures, from its three kinds of content, newest parent first. Each source
     * is read only behind the gate its screen is behind, and each tile opens the message, topic or
     * event the picture was posted with.
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
                    'images' => self::readableImages($viewer, $message->images, GroupMessageSerializer::image(...)),
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
                    'images' => self::readableImages($viewer, $topic->images, GroupTopicSerializer::image(...)),
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
                    'images' => self::readableImages($viewer, $event->images, GroupEventSerializer::image(...)),
                ];
            }
        }

        return UnifiedSections::photosFromParents($parents);
    }

    /**
     * One source's picture-bearing content, newest first and bounded: the strip can hold no more
     * tiles than this even if every parent brought a single picture. Ordered by when it was posted,
     * not when it was last touched — a comment on an old topic does not make its pictures new.
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
     * The pictures on one parent that this viewer may actually be served, shaped for the wire.
     *
     * The gate the strip's sources pass is the one on their screens, which is a question about the
     * group and not about a file: every candidate is asked again here, per file, through the same
     * policy that guards the bytes (FilePolicy). A file that refuses, or that is no longer there, is
     * left out in silence — no placeholder, no count, nothing in the payload that would say a picture
     * had been skipped.
     *
     * Lazy on purpose: photosFromParents walks this only as far as the cap reaches, so the policy
     * runs for the pictures the strip shows rather than for every one it might have shown.
     *
     * @param  Collection<int, Model>  $images
     * @param  callable(mixed): array  $shape
     * @return Generator<int, array>
     */
    private static function readableImages(Member $viewer, Collection $images, callable $shape): Generator
    {
        foreach ($images as $image) {
            if ($image->file !== null && Gate::forUser($viewer)->allows('view', $image->file)) {
                yield $shape($image);
            }
        }
    }
}
