<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Actions;

use App\Mcp\McpAbilities;
use App\Models\Member;

/**
 * Retire one of $member's MCP tokens, named by id.
 *
 * The id alone is not enough to be the one meant: the delete is scoped to this member's tokens and
 * to the name this endpoint stamps, so an id naming someone else's token, or a token minted for
 * some other purpose, deletes nothing and answers false — which the caller turns into the same
 * refusal an id naming nothing gets.
 *
 * No row lock, unlike the mint: there is nothing to serialize. The statement is one delete of one
 * named row, and a mint racing it creates a different row.
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
