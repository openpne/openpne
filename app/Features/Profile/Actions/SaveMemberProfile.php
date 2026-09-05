<?php

namespace App\Features\Profile\Actions;

use App\Features\Profile\Data\ProfileFormData;
use App\Models\Member;
use App\Models\Profile;
use App\Services\PresetProfileService;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Only `is_disp_config` fields are written, so a crafted payload cannot set a hidden field. Each
 * field's rows are replaced wholesale, in the storage shape the upgrade writes
 * (docs/internals/member-profile.md, "Storage model").
 */
class SaveMemberProfile
{
    public function __construct(private PresetProfileService $presets) {}

    public function __invoke(Member $member, ProfileFormData $data): void
    {
        DB::transaction(function () use ($member, $data): void {
            $member->update(['name' => $data->name]);
            $this->saveFields($member, Profile::query()->where('is_disp_config', true)->get(), $data);
        });
    }

    /**
     * The caller chooses the field set and owns the surrounding transaction.
     *
     * @param  Collection<int, Profile>  $profiles
     */
    public function saveFields(Member $member, Collection $profiles, ProfileFormData $data): void
    {
        foreach ($profiles as $profile) {
            $this->saveField($member, $profile, $data);
        }
    }

    private function saveField(Member $member, Profile $profile, ProfileFormData $data): void
    {
        $id = $profile->getKey();
        $raw = $data->values[$id] ?? null;
        $visibility = $this->visibilityFor($profile, $data->visibilities[$id] ?? null);

        $member->memberProfiles()->where('profile_id', $id)->delete();

        if ($profile->form_type === 'checkbox') {
            foreach (array_filter((array) $raw, fn ($v): bool => $v !== '' && $v !== null) as $optionId) {
                $this->insert($member, $profile, ['profile_option_id' => (int) $optionId, 'visibility' => $visibility]);
            }

            return;
        }

        $value = is_array($raw) ? null : $raw;
        if ($value === null || $value === '') {
            return;
        }

        $this->insert($member, $profile, $this->columnsFor($profile, (string) $value) + ['visibility' => $visibility]);
    }

    private function visibilityFor(Profile $profile, ?int $submitted): ?Visibility
    {
        if (! $profile->is_edit_public_flag || $submitted === null) {
            return null;
        }

        return Visibility::from($submitted);
    }

    /** @return array<string, mixed> */
    private function columnsFor(Profile $profile, string $value): array
    {
        if ($profile->form_type === 'date') {
            return $profile->isPreset() ? ['value_datetime' => Carbon::parse($value)] : ['value' => $value];
        }

        if (in_array($profile->form_type, ['select', 'radio'], true) && ! $this->presets->usesValueColumnForChoice($profile)) {
            return ['profile_option_id' => (int) $value];
        }

        return ['value' => $value];
    }

    /** @param array<string, mixed> $attrs */
    private function insert(Member $member, Profile $profile, array $attrs): void
    {
        $member->memberProfiles()->create(array_merge([
            'profile_id' => $profile->getKey(),
            'profile_option_id' => null,
            'value' => null,
            'value_datetime' => null,
        ], $attrs));
    }
}
