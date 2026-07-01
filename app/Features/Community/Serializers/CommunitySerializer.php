<?php

namespace App\Features\Community\Serializers;

use App\Models\Community;
use App\Models\CommunityMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Modern surface shapes for the Community feature. imageUrl is null (never '') when there is no
 * image so CommunityImage/Avatar fall back to their id-colored initial badge; role and policy are
 * string slugs, never raw ints, to avoid JS falsy-zero bugs. Viewer-specific authorization
 * (role/isPending/canManage/canJoin) is a top-level controller prop, not part of these shapes.
 */
class CommunitySerializer
{
    /**
     * @return array{id: int, name: string, description: string, memberCount: int, imageUrl: string|null, category: array{id: int, name: string}|null}
     */
    public static function summary(Community $community): array
    {
        return [
            'id' => $community->getKey(),
            'name' => $community->name,
            'description' => $community->description ?? '',
            // Search / ListMemberCommunities both withCount('members'); the fallback keeps a
            // route-bound community from silently reporting zero.
            'memberCount' => $community->members_count ?? $community->loadCount('members')->members_count,
            'imageUrl' => $community->image?->thumbnailUrl(180, 180, square: true),
            'category' => $community->category ? [
                'id' => $community->category->getKey(),
                'name' => $community->category->name,
            ] : null,
        ];
    }

    /**
     * Community top-page shape: summary plus the join policy, which drives the join-button label.
     *
     * @return array{id: int, name: string, description: string, memberCount: int, imageUrl: string|null, category: array{id: int, name: string}|null, registerPolicy: string}
     */
    public static function detail(Community $community): array
    {
        return [
            ...self::summary($community),
            'registerPolicy' => $community->register_policy->slug(),
        ];
    }

    /**
     * A confirmed member row: the member identity plus their community role slug. Requires the
     * member (and its avatar.file) to be loaded so serializing a list is not an N+1.
     *
     * @return array{id: int, name: string, imageUrl: string|null, role: string}
     */
    public static function member(CommunityMember $membership): array
    {
        $member = $membership->member;

        return [
            'id' => $member->getKey(),
            'name' => $member->name,
            'imageUrl' => $member->avatar?->file?->thumbnailUrl(76, 76, square: true),
            'role' => $membership->role->slug(),
        ];
    }

    /**
     * @param  iterable<CommunityMember>  $members
     * @return list<array{id: int, name: string, imageUrl: string|null, role: string}>
     */
    public static function members(iterable $members): array
    {
        $rows = [];
        foreach ($members as $membership) {
            $rows[] = self::member($membership);
        }

        return $rows;
    }

    /**
     * @param  LengthAwarePaginator<int, Community>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function paginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'summary'], $paginator->items()),
            'meta' => self::meta($paginator),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, CommunityMember>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function memberPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'member'], $paginator->items()),
            'meta' => self::meta($paginator),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array{currentPage: int, lastPage: int, perPage: int, total: int}
     */
    private static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
