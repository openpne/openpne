<?php

namespace App\Features\Member\Queries;

use App\Features\Profile\AgeVisibility;
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
 * custom date matches a range on `value`; country and region match `value`.
 *
 * The preset birthday is searched by month/day only (year stripped, like its display); the birth
 * year is exposed solely as the derived age, a separate criterion gated by AgeVisibility (not the
 * birthday field) — so a date range cannot infer the hidden age (mirrors VisibleAge).
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

    /** The registered profiles.name of the preset birthday field. */
    public function birthdayProfileName(): string
    {
        return $this->presets->nameForKey('birthday')['name'];
    }

    /**
     * @param  array<int|string, mixed>  $profileFilters  profile id => value (string, or list for checkbox)
     * @param  array<int|string, mixed>  $dateRanges  profile id => ['from' => Y-m-d, 'to' => Y-m-d]
     * @param  array<int|string, mixed>  $monthDayRanges  birthday profile id => [from_month, from_day, to_month, to_day]
     * @param  array{min?: mixed, max?: mixed}|null  $ageRange  derived age min/max (gated by AgeVisibility, not the birthday field)
     * @return LengthAwarePaginator<int, Member>
     */
    public function __invoke(Member $viewer, string $name, array $profileFilters, array $dateRanges, array $monthDayRanges = [], ?array $ageRange = null, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $query = Member::query()->with('avatar.file');

        if (trim($name) !== '') {
            $query->where('name', 'like', '%'.trim($name).'%');
        }

        $birthdayName = $this->birthdayProfileName();
        foreach ($this->searchableProfiles() as $profile) {
            $id = $profile->getKey();
            if ($profile->name === $birthdayName) {
                // The birthday is searched by month/day only; its year (= age) is searched separately,
                // gated by AgeVisibility, so a date range cannot infer the hidden birth year.
                $this->applyBirthdayFilter($query, $viewer, $profile, $monthDayRanges[$id] ?? null);
            } else {
                $this->applyFilter($query, $viewer, $profile, $profileFilters[$id] ?? null, $dateRanges[$id] ?? null);
            }
        }

        $this->applyAgeFilter($query, $viewer, $ageRange);

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

        $sub->whereRaw("{$effVis} <= {$this->clearanceCase()}", [$viewerId, $viewerId]);
    }

    /** The viewer's clearance for the correlated `members` row (two `?` bound to the viewer id). */
    private function clearanceCase(): string
    {
        return '(CASE WHEN members.id = ? THEN 3 '
            .'WHEN EXISTS (SELECT 1 FROM friendships WHERE friendships.member_id = members.id AND friendships.friend_id = ?) THEN 2 '
            .'ELSE 1 END)';
    }

    /** The birthday field, matched on month/day only (year stripped, like its display) and gated by the field's visibility. */
    private function applyBirthdayFilter(Builder $query, Member $viewer, Profile $profile, mixed $monthDay): void
    {
        $match = $this->monthDayMatch($monthDay);
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

    private function monthDayMatch(mixed $monthDay): ?callable
    {
        if (! is_array($monthDay)) {
            return null;
        }

        $from = $this->monthDayBound($monthDay['from_month'] ?? null, $monthDay['from_day'] ?? null);
        $to = $this->monthDayBound($monthDay['to_month'] ?? null, $monthDay['to_day'] ?? null);
        if ($from === null && $to === null) {
            return null;
        }

        $expr = $this->monthDayExpr();

        return function ($q) use ($expr, $from, $to): void {
            if ($from !== null && $to !== null) {
                // from > to wraps the year boundary (e.g. Dec→Feb): match the tail of the year OR the head.
                $from <= $to
                    ? $q->whereRaw("({$expr} >= ? AND {$expr} <= ?)", [$from, $to])
                    : $q->whereRaw("({$expr} >= ? OR {$expr} <= ?)", [$from, $to]);
            } elseif ($from !== null) {
                $q->whereRaw("{$expr} >= ?", [$from]);
            } else {
                $q->whereRaw("{$expr} <= ?", [$to]);
            }
        };
    }

    /** A 'MM-DD' bound from month/day inputs, or null when absent or not a real date (e.g. 2/31). */
    private function monthDayBound(mixed $month, mixed $day): ?string
    {
        $m = is_numeric($month) ? (int) $month : 0;
        $d = is_numeric($day) ? (int) $day : 0;

        // 2000 is a leap year, so 2/29 is accepted as a valid month/day bound.
        return ($m >= 1 && $d >= 1 && checkdate($m, $d, 2000)) ? sprintf('%02d-%02d', $m, $d) : null;
    }

    /** SQL extracting 'MM-DD' from the birthday value_datetime (the only cross-DB-divergent expression here). */
    private function monthDayExpr(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%m-%d', member_profiles.value_datetime)"
            : "DATE_FORMAT(member_profiles.value_datetime, '%m-%d')";
    }

    /**
     * Filter to members whose derived age is in [min, max], gated by AgeVisibility (not the birthday
     * field). Age comes from the birthday; an absent min is treated as 0 so the upper bound is always
     * today, excluding future birthdays (negative ages), matching VisibleAge.
     *
     * @param  array{min?: mixed, max?: mixed}|null  $ageRange
     */
    private function applyAgeFilter(Builder $query, Member $viewer, ?array $ageRange): void
    {
        if (! is_array($ageRange)) {
            return;
        }

        $min = isset($ageRange['min']) && is_numeric($ageRange['min']) ? max(0, (int) $ageRange['min']) : null;
        $max = isset($ageRange['max']) && is_numeric($ageRange['max']) ? (int) $ageRange['max'] : null;
        if ($min === null && $max === null) {
            return; // no age criterion
        }
        if ($max !== null && ($max < 0 || ($min !== null && $min > $max))) {
            return; // invalid range, ignore
        }

        $birthday = Profile::query()->where('name', $this->birthdayProfileName())->first();
        if ($birthday === null) {
            $query->whereRaw('1 = 0'); // age requested but no birthday field exists → no matches

            return;
        }

        $now = now();
        $upper = $now->copy()->subYears($min ?? 0)->endOfDay();              // born ≤ upper ⟺ age ≥ min (≤ today, no future)
        $lower = $max !== null ? $now->copy()->subYears($max + 1)->addDay()->startOfDay() : null; // born ≥ lower ⟺ age ≤ max

        $query->whereExists(function ($sub) use ($birthday, $upper, $lower): void {
            $sub->select(DB::raw(1))
                ->from('member_profiles')
                ->whereColumn('member_profiles.member_id', 'members.id')
                ->where('member_profiles.profile_id', $birthday->getKey())
                ->where('member_profiles.value_datetime', '<=', $upper);
            if ($lower !== null) {
                $sub->where('member_profiles.value_datetime', '>=', $lower);
            }
        });

        $this->applyAgeVisibility($query, $viewer);
    }

    /**
     * Constrain to members whose age is visible to the viewer: the stored AgeVisibility (absent or
     * malformed → Private, fail-closed like PreferenceKey::decode) within the viewer's clearance, and
     * a web-public (Open) age only when the SNS allows it — the same gate as VisibleAge.
     */
    private function applyAgeVisibility(Builder $query, Member $viewer): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        $keyCol = $grammar->wrap('member_preferences.key');     // `key` is reserved on MySQL
        $valCol = $grammar->wrap('member_preferences.value');
        $midCol = $grammar->wrap('member_preferences.member_id');
        $default = Visibility::Private->value;

        $raw = "COALESCE((SELECT {$valCol} FROM member_preferences WHERE {$midCol} = members.id AND {$keyCol} = 'age_visibility'), '{$default}')";
        // Whitelist before the numeric cast: a malformed value must fall to Private(3), never to Open(0).
        $effAge = "(CASE WHEN {$raw} IN ('0', '1', '2', '3') THEN {$raw} + 0 ELSE {$default} END)";
        $allowWeb = AgeVisibility::allowsWebPublic() ? '1 = 1' : '1 = 0';

        $query->whereRaw(
            "(({$effAge} <= {$this->clearanceCase()}) AND (({$effAge} <> 0) OR ({$allowWeb})))",
            [$viewer->getKey(), $viewer->getKey()],
        );
    }
}
