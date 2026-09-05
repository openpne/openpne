<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Actions;

use App\Mcp\McpAbilities;
use App\Models\Member;

/**
 * The delete is scoped to this member's tokens and to the name this endpoint stamps, so an id naming
 * someone else's token or one minted for another purpose deletes nothing and answers false. No row
 * lock, unlike the mint: this is one delete of one named row, and a mint racing it creates another.
 */
class RevokeMcpToken
{
    public function __invoke(Member $member, int $tokenId): bool
    {
        return $member->tokens()
            ->whereKey($tokenId)
            ->where('name', McpAbilities::TOKEN_NAME)
            ->delete() > 0;
    }
}
