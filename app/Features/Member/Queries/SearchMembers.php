<?php

namespace App\Features\Member\Queries;

use App\Models\Member;
use App\Models\Profile;
use App\Services\PresetProfileService;
use App\Support\Visibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Member search over the configurable profile fields (OpenPNE 3 /member/search).
 *
 * Each field filter is an EXISTS subquery on member_profiles, AND-connected. Matching is per
 * form_type and follows the storage model: a preset select/radio matches the choice key in
 * `value`, a custom one matches `profile_option_id`; a checkbox matches any chosen option; a
 * date matches a range on value_datetime (preset birthday) or value (custom date); country and
 * region match `value`.
 *
 * Privacy: a match only counts when the value is visible to the viewer — its effective
 * visibility (per-value flag or the field default) must be within the viewer's clearance for
 * that owner (self → all, friend → up to Friends, otherwise up to Members), so search cannot
 * probe a value the viewer could not otherwise see. Owners who block the viewer are excluded
 * entirely, and a forcibly-private field (default Private, not member-editable) is dropped from
 * the form because no one else can match on it (OpenPNE 3's is_check_public_flag).
 */
class SearchMembers
{
    public const PER_PAGE = 20;

    /** @var Collection<int, Profile>|null */
    private ?Collection $profilesCache = null;

    public function __construct(private PresetProfileService $presets) {}

    /** @return Collection<int, Profile> */
    public function searchableProfiles(): Collection
    {
        return $this->profilesCache ??= Profile::query()
            ->with(['translations', 'options.translations'])
            ->where('is_disp_search', true)
            ->where(fn (Builder $q) => $q
                ->where('is_edit_public_flag', true)
                ->orWhere('default_visibility', '!=', Visibility::Private->value))
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @param  array<int|string, mixed>  $profileFilters  profile id => value (string, or list for checkbox)
     * @param  array<int|string, mixed>  $dateRanges  profile id => ['from' => Y-m-d, 'to' => Y-m-d]
     * @return LengthAwarePaginator<int, Member>
     */
    public function __invoke(Member $viewer, string $name, array $profileFilters, array $dateRanges, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $query = Member::query()->with('primaryImage.file');

        if (trim($name) !== '') {
            $query->where('name', 'like', '%'.trim($name).'%');
        }

        foreach ($this->searchableProfiles() as $profile) {
            $id = $profile->getKey();
            $this->applyFilter($query, $viewer, $profile, $profileFilters[$id] ?? null, $dateRanges[$id] ?? null);
        }

        // Hide owners who block the viewer (owner→viewer block), like Diary and Profile.
        $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))
            ->from('member_blocks')
            ->whereColumn('member_blocks.blocker_id', 'members.id')
            ->where('member_blocks.blocked_id', $viewer->getKey()));

        return $query->orderByDesc('created_at')->paginate($perPage)->withQueryString();
    }

    private function applyFilter(Builder $query, Member $viewer, Profile $profile, mixed $value, mixed $range): void
    {
        $match = $this->matchFor($profile, $value, $range);
        if ($match === null) {
            return;
        }

        $query->whereExists(function ($sub) use ($profile, $viewer, $match): void {
            $sub->select(DB::raw(1))
                ->from('member_profiles')
                ->whereColumn('member_profiles.member_id', 'members.id')
                ->where('member_profiles.profile_id', $profile->getKey());
            $match($sub);
            $this->applyVisibility($sub, $profile, $viewer);
        });
    }

    /** The match closure for this field's input, or null when the field has no criterion. */
    private function matchFor(Profile $profile, mixed $value, mixed $range): ?callable
    {
        switch ($profile->form_type) {
            case 'input':
            case 'textarea':
                $text = is_string($value) ? trim($value) : '';

                return $text === '' ? null : fn ($q) => $q->where('member_profiles.value', 'like', '%'.$text.'%');

            case 'select':
            case 'radio':
                if (! is_string($value) || $value === '') {
                    return null;
                }

                return $this->presets->usesValueColumnForChoice($profile)
                    ? fn ($q) => $q->where('member_profiles.value', $value)
                    : fn ($q) => $q->where('member_profiles.profile_option_id', (int) $value);

            case 'checkbox':
                $ids = is_array($value) ? array_values(array_filter(array_map('intval', $value))) : [];

                return $ids === [] ? null : fn ($q) => $q->whereIn('member_profiles.profile_option_id', $ids);

            case 'date':
                return $this->dateMatch($profile, $range);

            case 'country_select':
            case 'region_select':
                return (! is_string($value) || $value === '') ? null : fn ($q) => $q->where('member_profiles.value', $value);
        }

        return null;
    }

    private function dateMatch(Profile $profile, mixed $range): ?callable
    {
        $from = is_array($range) && is_string($range['from'] ?? null) && $range['from'] !== '' ? $range['from'] : null;
        $to = is_array($range) && is_string($range['to'] ?? null) && $range['to'] !== '' ? $range['to'] : null;
        if ($from === null && $to === null) {
            return null;
        }

        // A preset date (birthday) lives in value_datetime (stored at 00:00:00); a custom date is
        // the Y-m-d string in value. For value_datetime, stretch the upper bound to end-of-day so
        // a date-only "to" still includes that day under both MySQL and SQLite string comparison.
        $column = $profile->isPreset() ? 'member_profiles.value_datetime' : 'member_profiles.value';
        $toBound = ($profile->isPreset() && $to !== null) ? $to.' 23:59:59' : $to;

        return function ($q) use ($from, $toBound, $column): void {
            if ($from !== null) {
                $q->where($column, '>=', $from);
            }
            if ($toBound !== null) {
                $q->where($column, '<=', $toBound);
            }
        };
    }

    /**
     * Constrain the matched value to one the viewer may see. effVis (per-value flag, or the field
     * default when the field is not member-editable) must be within the viewer's clearance for the
     * owner row: self → Private(3), friend → Friends(2), otherwise Members(1) on the Visibility scale.
     */
    private function applyVisibility(QueryBuilder $sub, Profile $profile, Member $viewer): void
    {
        $viewerId = $viewer->getKey();
        $default = $profile->default_visibility->value;
        $effVis = $profile->is_edit_public_flag
            ? "COALESCE(member_profiles.visibility, {$default})"
            : (string) $default;

        $sub->whereRaw(
            "{$effVis} <= (CASE WHEN members.id = ? THEN 3 "
            .'WHEN EXISTS (SELECT 1 FROM friendships WHERE friendships.member_id = members.id AND friendships.friend_id = ?) THEN 2 '
            .'ELSE 1 END)',
            [$viewerId, $viewerId],
        );
    }
}
