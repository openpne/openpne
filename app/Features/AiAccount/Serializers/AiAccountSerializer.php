<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Serializers;

use App\Features\AiAccount\AiAccountSettings;
use App\Features\AiAccount\AiTokenReauth;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Mcp\McpAbilities;
use App\Models\Member;
use App\Models\Profile;
use App\Support\Feature;
use Illuminate\Contracts\Session\Session;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The identity half is MemberRefSerializer's, so an account's avatar and AI mark read the same here
 * as anywhere else.
 */
class AiAccountSerializer
{
    /**
     * Flash payload `{member_id: int, token: string}`: the id is what keeps one account's credential
     * from being rendered on another's page.
     */
    public const NEW_TOKEN = 'ai_account.new_token';

    /**
     * Shared so the Modern page and the Classic category cannot disagree about whether the create
     * form is offered.
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
            // Advisory only: the creation re-reads both under the owner lock.
            'canCreate' => $enabled && $accounts->count() < $limit,
        ];
    }

    /**
     * @return array{tokens: list<array{id: int, readOnly: bool, createdAt: string, lastUsedAt: string|null}>, requiresPassword: bool, mcpEnabled: bool, newToken: string|null}
     */
    public static function tokens(Member $aiAccount, Session $session): array
    {
        $tokens = $aiAccount->tokens()->where('name', McpAbilities::TOKEN_NAME)->orderByDesc('id')->get();

        return [
            'tokens' => array_map(self::token(...), $tokens->all()),
            // Lockstep with AiTokenRequest: the form offers the password field exactly when the
            // request will demand it.
            'requiresPassword' => ! AiTokenReauth::isFresh($session),
            // Reported, not a gate: the unit is the endpoint's kill switch and a token outlives it
            // being thrown.
            'mcpEnabled' => Feature::Mcp->enabled(),
            'newToken' => self::mintedFor($aiAccount, $session),
        ];
    }

    /**
     * Null when the install has no such field, the same null that keeps the POST from writing one.
     *
     * @return array{label: string, value: string, maxLength: int|null}|null
     */
    public static function selfIntroduction(Member $aiAccount, ?Profile $field, string $lang): ?array
    {
        if ($field === null) {
            return null;
        }

        $max = $field->value_max;

        return [
            'label' => $field->getCaption($lang),
            'value' => (string) ($aiAccount->memberProfiles()->where('profile_id', $field->getKey())->value('value') ?? ''),
            'maxLength' => ($max === null || $max === '') ? null : (int) $max,
        ];
    }

    private static function mintedFor(Member $aiAccount, Session $session): ?string
    {
        $minted = $session->get(self::NEW_TOKEN);

        if (! is_array($minted) || ($minted['member_id'] ?? null) !== (int) $aiAccount->getKey()) {
            return null;
        }

        return is_string($minted['token'] ?? null) ? $minted['token'] : null;
    }

    /**
     * @return array{id: int, readOnly: bool, createdAt: string, lastUsedAt: string|null}
     */
    private static function token(PersonalAccessToken $token): array
    {
        return [
            'id' => (int) $token->getKey(),
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
            // The fallback keeps a route-bound account, loaded without `withCount`, from reporting
            // zero.
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
