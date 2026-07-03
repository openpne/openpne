<?php

namespace App\Features\Member\Queries;

use App\Models\Member;
use App\Models\Profile;
use App\Services\PresetProfileService;
use Illuminate\Support\Facades\DB;

/**
 * The self-introduction (op_preset_self_introduction) of each given member, keyed by member id,
 * but only where the value is visible to $viewer under the field-visibility gate the profile page
 * and member search already enforce: the field's effective visibility (per-value flag, or the field
 * default when the field is not per-value editable) must be within the viewer's clearance for that
 * owner. An owner who blocks the viewer, an empty value, and a value above the viewer's clearance are
 * simply absent from the map — as is the whole thing when the install has no such field registered
 * (fail-closed, never an error).
 *
 * One query for the whole result page (the effVis/clearance SQL mirrors SearchMembers::applyVisibility)
 * so the person-row list stays flat instead of reading a relation per row.
 */
class VisibleSelfIntroductions
{
    public function __construct(private PresetProfileService $presets) {}

    /**
     * @param  list<int>  $memberIds
     * @return array<int, string>
     */
    public function __invoke(Member $viewer, array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $name = $this->presets->nameForKey('self_introduction')['name'];
        $profile = Profile::query()->where('name', $name)->first();
        if ($profile === null) {
            return []; // field not registered on this install
        }

        $default = $profile->default_visibility->value;
        $effVis = $profile->is_edit_public_flag
            ? "COALESCE(member_profiles.visibility, {$default})"
            : (string) $default;
        $viewerId = $viewer->getKey();

        /** @var array<int, string> $intros */
        $intros = DB::table('member_profiles')
            ->where('profile_id', $profile->getKey())
            ->whereIn('member_id', $memberIds)
            ->whereNotNull('value')
            ->where('value', '<>', '')
            ->whereRaw("{$effVis} <= {$this->clearanceCase()}", [$viewerId, $viewerId])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('member_blocks')
                ->whereColumn('member_blocks.blocker_id', 'member_profiles.member_id')
                ->where('member_blocks.blocked_id', $viewerId))
            ->pluck('value', 'member_id')
            ->all();

        return $intros;
    }

    /** The viewer's clearance for the owning member_profiles row (two `?` bound to the viewer id). */
    private function clearanceCase(): string
    {
        return '(CASE WHEN member_profiles.member_id = ? THEN 3 '
            .'WHEN EXISTS (SELECT 1 FROM friendships WHERE friendships.member_id = member_profiles.member_id AND friendships.friend_id = ?) THEN 2 '
            .'ELSE 1 END)';
    }
}
