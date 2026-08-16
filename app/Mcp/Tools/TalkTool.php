<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\GroupTalkAccess;
use App\Models\Group;
use App\Models\Member;
use App\Support\Feature;

/**
 * What the talk tools share on top of {@see McpTool}: the room a call names, and talk's own refusal.
 */
abstract class TalkTool extends McpTool
{
    /** A room the caller may not read, a message that is not in it, a cursor that does not parse. */
    protected const REFUSED = 'No such talk room, message or position — or it is not yours to read.';

    /**
     * Talk switched off takes its tools with it, so they are not listed and calling one by name is
     * answered as a tool that does not exist. The `mcp` unit is the endpoint's own switch and does
     * not contain these (Feature::parent()); what a tool reaches into is what decides whether it is
     * there, which is how the endpoint holds a second feature's tools.
     */
    public function shouldRegister(): bool
    {
        return Feature::GroupTalk->enabled();
    }

    /** The room named, or null when it is not there or not the caller's to read. */
    protected function readableRoom(Member $member, int $groupId): ?Group
    {
        $group = Group::query()->find($groupId);

        return $group !== null && GroupTalkAccess::canView($group, $member) ? $group : null;
    }
}
