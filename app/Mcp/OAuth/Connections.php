<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use App\Features\AiAccount\ActorLock;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Mcp\McpAbilities;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

/**
 * The OAuth grants a member can see and end: their own and those of the AI accounts they own,
 * addressed by `user_id` (never through Passport's deprecated user relation). Live means the
 * access token or its refresh token is still usable, the rows a prune would keep.
 */
final class Connections
{
    /** @return list<array<string, mixed>> */
    public static function listFor(Member $viewer): array
    {
        $subjects = self::subjectsOf($viewer);

        return self::live($subjects)->with('client')->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn (Token $token): array => [
                'id' => $token->getKey(),
                'clientName' => (string) $token->client?->name,
                'host' => (string) parse_url((string) ($token->client?->redirect_uris[0] ?? ''), PHP_URL_HOST),
                'actor' => (int) $token->user_id === (int) $viewer->getKey() ? null : MemberRefSerializer::ref($subjects[(int) $token->user_id]),
                'readOnly' => ! in_array(McpAbilities::WRITE, (array) $token->scopes, true),
                'createdAt' => $token->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * On the subject's locked row, the order a refresh takes, so a rotation in flight cannot leave a
     * fresh pair behind the one ended here.
     *
     * @return int|null the member the ended grant spoke for, or null when it was not a live grant of the viewer's
     */
    public static function revoke(Member $viewer, string $tokenId): ?int
    {
        return DB::transaction(function () use ($viewer, $tokenId): ?int {
            $subjects = self::subjectsOf($viewer);
            $subjectId = self::live($subjects)->whereKey($tokenId)->value('user_id');

            if ($subjectId === null || ActorLock::acquire((int) $subjectId) === null) {
                return null;
            }

            $token = self::live($subjects)->whereKey($tokenId)->first();

            if ($token === null) {
                return null;
            }

            Passport::refreshToken()->newQuery()->where('access_token_id', $token->getKey())->delete();
            $token->delete();

            return (int) $subjectId;
        });
    }

    public static function countFor(Member $subject): int
    {
        return self::liveQuery()->where('user_id', $subject->getKey())->count();
    }

    /**
     * @param  Collection<int, Member>  $subjects
     * @return Builder<Token>
     */
    private static function live(Collection $subjects): Builder
    {
        return self::liveQuery()->whereIn('user_id', $subjects->keys()->all());
    }

    /** @return Builder<Token> */
    private static function liveQuery(): Builder
    {
        return Passport::token()->newQuery()
            ->where('revoked', false)
            ->where(fn (Builder $q) => $q
                ->where('expires_at', '>', now())
                ->orWhereHas('refreshToken', fn (Builder $r) => $r->where('revoked', false)->where('expires_at', '>', now())));
    }

    /** @return Collection<int, Member> keyed by id: the viewer and the AI accounts they own */
    private static function subjectsOf(Member $viewer): Collection
    {
        return $viewer->aiAccounts()->with('avatar.file')->get()->push($viewer)->keyBy(fn (Member $m) => (int) $m->getKey());
    }
}
