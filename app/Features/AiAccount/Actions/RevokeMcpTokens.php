<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Actions;

use App\Mcp\McpAbilities;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Retire every MCP token $member holds, and only those: a token minted for some other purpose is
 * not collateral damage of taking this endpoint's access away.
 *
 * On the member's row lock, the same one the mint takes, so a revoke cannot report "0" while a
 * token minted a moment earlier lands right behind it.
 */
class RevokeMcpTokens
{
    /** @return int how many were deleted */
    public function __invoke(Member $member): int
    {
        return DB::transaction(function () use ($member): int {
            $locked = Member::whereKey($member->getKey())->lockForUpdate()->first();

            return $locked === null ? 0 : $locked->tokens()->where('name', McpAbilities::TOKEN_NAME)->delete();
        });
    }
}
