<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Serializers;

use App\Features\AiAccount\AiAccountSettings;
use App\Features\AiAccount\AiTokenReauth;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Mcp\McpAbilities;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Contracts\Session\Session;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Modern surface shapes for a member's own AI accounts. The identity half is MemberRefSerializer's
 * — an AI account IS a member, and its avatar and AI mark must read the same here as anywhere else.
 */
class AiAccountSerializer
{
    /** Flash key carrying the plaintext credential through the redirect that follows a mint. */
    public const NEW_TOKEN = 'ai_account.new_token';

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
     * The token half of an account's page, on either surface: what it holds, whether the password
     * is due, and the credential just minted — which exists on exactly one render.
     *
     * @return array{tokens: list<array{id: int, readOnly: bool, createdAt: string, lastUsedAt: string|null}>, requiresPassword: bool, mcpEnabled: bool, newToken: string|null}
     */
    public static function tokens(Member $aiAccount, Session $session): array
    {
        $tokens = $aiAccount->tokens()->where('name', McpAbilities::TOKEN_NAME)->orderByDesc('id')->get();
        $newToken = $session->get(self::NEW_TOKEN);

        return [
            'tokens' => array_map(self::token(...), $tokens->all()),
            // Lockstep with AiTokenRequest: the form offers the password field exactly when the
            // request will demand it.
            'requiresPassword' => ! AiTokenReauth::isFresh($session),
            // Not a gate on any of this — the unit is the endpoint's kill switch and a token
            // outlives it being thrown. Reported so a member whose brand-new token is answered 404
            // is not left guessing why.
            'mcpEnabled' => Feature::Mcp->enabled(),
            'newToken' => is_string($newToken) ? $newToken : null,
        ];
    }

    /**
     * @return array{id: int, readOnly: bool, createdAt: string, lastUsedAt: string|null}
     */
    private static function token(PersonalAccessToken $token): array
    {
        return [
            'id' => (int) $token->getKey(),
            // The name is fixed, so what distinguishes one token from another is its reach and when
            // it was last heard from.
            'readOnly' => ! in_array(McpAbilities::WRITE, (array) $token->abilities, true),
            'createdAt' => $token->created_at->toIso8601String(),
            'lastUsedAt' => $token->last_used_at?->toIso8601String(),
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
