<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\GroupTalkAccess;
use App\Mcp\McpAbilities;
use App\Models\Group;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Auth\AuthenticationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * What the four talk tools share: who is calling, and the single refusal they all give.
 */
abstract class TalkTool extends Tool
{
    /**
     * The one answer to every refusal a room can produce — an id that names nothing, a room the
     * caller may not read, a message that is not in it, a cursor that does not parse. Distinguishing
     * them would let a caller enumerate rooms and messages it cannot see, which is the same reason
     * the web surface answers 404 to all four (docs/internals/group-talk.md).
     */
    protected const REFUSED = 'No such talk room, message or position — or it is not yours to read.';

    /** Missing ability is NOT hidden: the caller can act on it, and it says nothing about what exists. */
    protected const MISSING_WRITE = 'This token may only read. Writing needs the '.McpAbilities::WRITE.' ability.';

    /**
     * Talk switched off takes its tools with it, so they are not listed and calling one by name is
     * answered as a tool that does not exist. The `mcp` unit is the endpoint's own switch and does
     * not contain these (Feature::parent()); what a tool reaches into is what decides whether it is
     * there, which is how the endpoint will hold a second feature's tools later.
     */
    public function shouldRegister(): bool
    {
        return Feature::GroupTalk->enabled();
    }

    /** The member the token stands for; the endpoint authenticated before any tool ran (routes/ai.php). */
    protected function member(Request $request): Member
    {
        $member = $request->user();

        return $member instanceof Member ? $member : throw new AuthenticationException;
    }

    /** The room named, or null when it is not there or not the caller's to read. */
    protected function readableRoom(Member $member, int $groupId): ?Group
    {
        $group = Group::query()->find($groupId);

        return $group !== null && GroupTalkAccess::canView($group, $member) ? $group : null;
    }

    protected function refused(): Response
    {
        return Response::error(static::REFUSED);
    }
}
