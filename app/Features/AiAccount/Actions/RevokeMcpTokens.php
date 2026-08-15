<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Actions;

use App\Features\AiAccount\MemberSelector;
use App\Mcp\McpAbilities;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Retire every MCP token $member holds, and only those: a token minted for some other purpose is
 * not collateral damage of taking this endpoint's access away.
 *
 * On the member's row lock, the same one the mint takes, so a revoke cannot report "0" while a
 * token minted a moment earlier lands right behind it — and, like the mint, whoever was asked for is
 * confirmed on that locked row rather than taken from the caller's earlier lookup.
 */
class RevokeMcpTokens
{
    /** @return int|null how many were deleted, or null when the row asked for is no longer that row */
    public function __invoke(Member|MemberSelector $member): ?int
    {
        $selector = $member instanceof Member ? MemberSelector::of($member) : $member;

        return DB::transaction(function () use ($selector): ?int {
            $locked = Member::whereKey($selector->member()->getKey())->lockForUpdate()->first();

            return $locked === null || ! $selector->names($locked)
                ? null
                : $locked->tokens()->where('name', McpAbilities::TOKEN_NAME)->delete();
        });
    }
}
