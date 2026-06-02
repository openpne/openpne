<?php

namespace App\Features\Profile\Actions;

use App\Features\Profile\Data\ProfileFormData;
use App\Models\Member;
use App\Models\Profile;
use App\Services\PresetProfileService;
use App\Support\Visibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persists a member's profile-edit submission. Only is_disp_config fields are written (extra
 * posted keys are ignored), so a crafted payload cannot set hidden fields. Each field's rows are
 * replaced wholesale, which uniformly handles empties (delete), single↔multi transitions, and a
 * checkbox's variable row set. Storage shape per form_type matches MemberProfileUpgrade so
 * migrated and freshly edited data are read back the same way:
 *   - checkbox          one row per chosen option (profile_option_id)
 *   - custom select/radio   profile_option_id
 *   - preset select/radio   the choice key in value
 *   - preset date           value_datetime; custom date  the Y-m-d string in value
 *   - input/textarea/country/region   value
 * Per-value visibility is stored only for member-editable fields; otherwise null (the read layer
 * falls back to the field default).
 */
class SaveMemberProfile
{
    public function __construct(private PresetProfileService $presets) {}

    public function __invoke(Member $member, ProfileFormData $data): void
    {
        DB::transaction(function () use ($member, $data): void {
            $member->update(['name' => $data->name]);

            foreach (Profile::query()->where('is_disp_config', true)->get() as $profile) {
                $this->saveField($member, $profile, $data);
            }
        });
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
