<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Serializers;

use App\Features\AiAccount\AiAccountSettings;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Models\Member;

/**
 * Modern surface shapes for a member's own AI accounts. The identity half is MemberRefSerializer's
 * — an AI account IS a member, and its avatar and AI mark must read the same here as anywhere else.
 */
class AiAccountSerializer
{
    /**
     * What a member's AI-account list page is, on either surface: what they own, and whether there
     * is room and permission for one more. Shared so the Modern page and the Classic category
     * cannot disagree about whether the create form is offered.
     *
     * @return array{accounts: list<array<string, mixed>>, used: int, limit: int, enabled: bool, canCreate: bool}
     */
    public static function list(Member $viewer, AiAccountSettings $settings): array
    {
        $accounts = $viewer->aiAccounts()->with('avatar.file')->withCount('groupMemberships')->orderBy('id')->get();
        $limit = $settings->limit();
        $enabled = $settings->enabled();

        return [
            'accounts' => self::accounts($accounts),
            'used' => $accounts->count(),
            'limit' => $limit,
            'enabled' => $enabled,
            // Advisory: CreateAiAccount re-reads both under the owner lock, so this only decides
            // whether the form is worth showing, never whether a creation is allowed.
            'canCreate' => $enabled && $accounts->count() < $limit,
        ];
    }

    /**
     * @return array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool, groupCount: int}
     */
    public static function account(Member $aiAccount): array
    {
        return [
            ...MemberRefSerializer::ref($aiAccount),
            // withCount('groupMemberships') on the listing query; the fallback keeps a route-bound
            // account from silently reporting zero.
            'groupCount' => (int) ($aiAccount->group_memberships_count ?? $aiAccount->groupMemberships()->count()),
        ];
    }

    /**
     * @param  iterable<Member>  $aiAccounts
     * @return list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool, groupCount: int}>
     */
    public static function accounts(iterable $aiAccounts): array
    {
        $rows = [];
        foreach ($aiAccounts as $aiAccount) {
            $rows[] = self::account($aiAccount);
        }

        return $rows;
    }
}
