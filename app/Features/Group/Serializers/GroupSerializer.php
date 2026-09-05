<?php

namespace App\Features\Group\Serializers;

use App\Features\Member\Serializers\MemberRefSerializer;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * imageUrl is null, never '', when there is no image, so the client falls back to its neutral
 * initial badge; role and policy are string slugs, never raw ints. Viewer-specific authorization is
 * a top-level controller prop, not part of these shapes.
 */
class GroupSerializer
{
    /**
     * @return array{id: int, name: string, description: string, memberCount: int, imageUrl: string|null, category: array{id: int, name: string}|null}
     */
    public static function summary(Group $group): array
    {
        return [
            'id' => $group->getKey(),
            'name' => $group->name,
            'description' => $group->description ?? '',
            // The fallback keeps a group bound without withCount('members') from reporting zero.
            'memberCount' => $group->members_count ?? $group->loadCount('members')->members_count,
            'imageUrl' => $group->image?->thumbnailUrl(180, 180, square: true),
            'category' => $group->category ? [
                'id' => $group->category->getKey(),
                'name' => $group->category->name,
            ] : null,
        ];
    }

    /**
     * @return array{id: int, name: string, description: string, memberCount: int, imageUrl: string|null, category: array{id: int, name: string}|null, registerPolicy: string}
     */
    public static function detail(Group $group): array
    {
        return [
            ...self::summary($group),
            'registerPolicy' => $group->register_policy->slug(),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Group>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function detailPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'detail'], $paginator->items()),
            'meta' => self::meta($paginator),
        ];
    }

    /**
     * Requires `member` and its `avatar.file` to be loaded, so serializing a list is not an N+1.
     *
     * @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool, role: string}
     */
    public static function member(GroupMember $membership): array
    {
        return [
            ...MemberRefSerializer::ref($membership->member),
            'role' => $membership->role->slug(),
        ];
    }

    /**
     * @param  iterable<GroupMember>  $members
     * @return list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool, role: string}>
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
     * @param  LengthAwarePaginator<int, Group>  $paginator
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
     * @param  LengthAwarePaginator<int, GroupMember>  $paginator
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
     * Requires `avatar.file` to be loaded, so serializing a list is not an N+1.
     *
     * @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool}
     */
    public static function applicant(Member $member): array
    {
        return MemberRefSerializer::ref($member);
    }

    /**
     * @param  LengthAwarePaginator<int, Member>  $paginator
     * @return array{data: list<array>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}}
     */
    public static function applicantPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map([self::class, 'applicant'], $paginator->items()),
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
