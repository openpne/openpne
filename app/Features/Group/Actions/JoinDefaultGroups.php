<?php

namespace App\Features\Group\Actions;

use App\Features\Group\GroupRole;
use App\Features\GroupTalk\TalkReadCursor;
use App\Models\Group;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Support\Facades\DB;

/**
 * A plain Member row in every default group, whatever its join policy and with no join notification,
 * as OpenPNE 3 opAuthRegisterForm::save() joined the is_default communities.
 */
class JoinDefaultGroups
{
    public function __invoke(Member $member): void
    {
        $groupIds = Group::query()->where('is_default', true)->orderBy('id')->pluck('id');
        if ($groupIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $groupIds->map(fn (mixed $groupId): array => [
            'group_id' => (int) $groupId,
            'member_id' => $member->getKey(),
            'role' => GroupRole::Member->value,
            'created_at' => $now,
            'updated_at' => $now,
            ...TalkReadCursor::snapshot((int) $groupId),
        ])->all();

        foreach (array_chunk($rows, 100) as $part) {
            DB::table('group_members')->insertOrIgnore($part);
        }

        ViewerRelations::flush();
    }
}
