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
 * A connection is one client speaking for one member, the pair rather than a token row, since
 * rotation replaces the row every hour. Live means the access token or its refresh token is still
 * usable, addressed by `user_id` and never through Passport's deprecated user relation.
 */
final class Connections
{
    /** @return list<array<string, mixed>> */
    public static function listFor(Member $viewer): array
    {
        $subjects = self::subjectsOf($viewer);

        return self::live($subjects)->with('client')->get()
            ->groupBy(fn (Token $token): string => $token->client_id.':'.$token->user_id)
            ->map(function (Collection $tokens) use ($viewer, $subjects): array {
                /** @var Token $latest */
                $latest = $tokens->sortByDesc('created_at')->first();

                return [
                    'client' => (string) $latest->client_id,
                    'subject' => (int) $latest->user_id,
                    'clientName' => (string) $latest->client?->name,
                    'host' => (string) parse_url((string) ($latest->client?->redirect_uris[0] ?? ''), PHP_URL_HOST),
                    'actor' => (int) $latest->user_id === (int) $viewer->getKey() ? null : MemberRefSerializer::ref($subjects[(int) $latest->user_id]),
                    'readOnly' => ! in_array(McpAbilities::WRITE, (array) $latest->scopes, true),
                    // The oldest surviving row: the sweep of long-expired tokens moves it forward over time.
                    'createdAt' => $tokens->min('created_at')?->toIso8601String(),
                    'lastRenewedAt' => $latest->created_at?->toIso8601String(),
                ];
            })
            ->sortByDesc('lastRenewedAt')
            ->values()
            ->all();
    }

    /**
     * Every code and token the pair holds, on the subject's locked row (the order a refresh takes),
     * so a rotation in flight cannot leave a fresh pair behind the one ended here.
     *
     * @return bool whether the pair was a live connection of the viewer's
     */
    public static function revoke(Member $viewer, string $clientId, int $subjectId): bool
    {
        return DB::transaction(function () use ($viewer, $clientId, $subjectId): bool {
            if (! self::subjectsOf($viewer)->has($subjectId) || ActorLock::acquire($subjectId) === null) {
                return false;
            }

            $pair = fn (Builder $q): Builder => $q->where('user_id', $subjectId)->where('client_id', $clientId);

            if (! self::liveQuery()->where($pair)->exists()) {
                return false;
            }

            Passport::refreshToken()->newQuery()
                ->whereIn('access_token_id', Passport::token()->newQuery()->where($pair)->select('id'))
                ->delete();
            Passport::token()->newQuery()->where($pair)->delete();
            Passport::authCode()->newQuery()->where($pair)->delete();

            return true;
        });
    }

    public static function countFor(Member $subject): int
    {
        return self::liveQuery()->where('user_id', $subject->getKey())->distinct()->count('client_id');
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
