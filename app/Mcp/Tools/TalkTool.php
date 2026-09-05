<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Features\GroupTalk\GroupTalkAccess;
use App\Models\Group;
use App\Models\Member;
use App\Support\Feature;

abstract class TalkTool extends McpTool
{
    protected const REFUSED = 'No such talk room, message or position — or it is not yours to read.';

    public function shouldRegister(): bool
    {
        return Feature::GroupTalk->enabled();
    }

    protected function readableRoom(Member $member, int $groupId): ?Group
    {
        $group = Group::query()->find($groupId);

        return $group !== null && GroupTalkAccess::canView($group, $member) ? $group : null;
    }
}
