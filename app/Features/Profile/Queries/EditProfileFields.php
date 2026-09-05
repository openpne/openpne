<?php

namespace App\Features\Profile\Queries;

use App\Features\Profile\Data\EditableField;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Services\PresetProfileService;
use App\Support\Visibility;
use Illuminate\Support\Collection;

class EditProfileFields
{
    public function __construct(private PresetProfileService $presets) {}

    /** @return Collection<int, EditableField> */
    public function __invoke(Member $member): Collection
    {
        $current = $member->memberProfiles()->get()->groupBy('profile_id');

        return Profile::query()
            ->with(['translations', 'options.translations'])
            ->where('is_disp_config', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Profile $profile): EditableField => new EditableField(
                $profile,
                $this->currentValue($profile, $current->get($profile->getKey())),
                $this->currentVisibility($profile, $current->get($profile->getKey())),
            ));
    }

    /**
     * @param  Collection<int, MemberProfile>|null  $rows
     * @return string|list<int>
     */
    private function currentValue(Profile $profile, ?Collection $rows): string|array
    {
        if ($rows === null || $rows->isEmpty()) {
            return $profile->form_type === 'checkbox' ? [] : '';
        }

        if ($profile->form_type === 'checkbox') {
            return $rows->pluck('profile_option_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        }

        $row = $rows->first();

        return match (true) {
            $profile->form_type === 'date' => $row->value_datetime?->format('Y-m-d') ?? (string) $row->value,
            $this->usesOptionId($profile) => (string) ($row->profile_option_id ?? ''),
            default => (string) ($row->value ?? ''),
        };
    }

    /**
     * The stored audience goes back as the sticky current, so a tier no longer offered (Friends once
     * friends are off) stays selectable and an unrelated save cannot widen the row. Open stays
     * clamped: on a field whose `is_public_web` was turned off it would post an out-of-range value.
     *
     * @param  Collection<int, MemberProfile>|null  $rows
     */
    private function currentVisibility(Profile $profile, ?Collection $rows): Visibility
    {
        $stored = $rows?->first()?->visibility;
        $resolved = $stored ?? $profile->default_visibility;

        return in_array($resolved, $profile->visibilityOptions($stored), true) ? $resolved : Visibility::Members;
    }

    /** A custom select/radio stores the chosen option id; a preset stores the choice key in value. */
    private function usesOptionId(Profile $profile): bool
    {
        return in_array($profile->form_type, ['select', 'radio'], true)
            && ! $this->presets->usesValueColumnForChoice($profile);
    }
}
