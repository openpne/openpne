<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\McpAbilities;
use App\Models\Member;
use Illuminate\Auth\AuthenticationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Each feature's base narrows REFUSED to its own nouns; nothing narrows it per tool, or the set of
 * them would say what one of them alone does not.
 */
abstract class McpTool extends Tool
{
    protected const REFUSED = 'No such record — or it is not yours to read.';

    /** Missing ability is NOT hidden: the caller can act on it, and it says nothing about what exists. */
    protected const MISSING_WRITE = 'This token may only read. Writing needs the '.McpAbilities::WRITE.' ability.';

    /** The endpoint authenticated before any tool ran (routes/ai.php). */
    protected function member(Request $request): Member
    {
        $member = $request->user();

        return $member instanceof Member ? $member : throw new AuthenticationException;
    }

    protected function refused(): Response
    {
        return Response::error(static::REFUSED);
    }
}
