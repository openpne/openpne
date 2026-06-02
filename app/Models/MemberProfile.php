<?php

namespace App\Models;

use App\Services\PresetProfileService;
use App\Support\Visibility;
use Database\Factories\MemberProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's value for one profile field. A single-value field is one row; a checkbox is
 * one row per chosen option (each with profile_option_id); a custom date is one row holding
 * the composed Y-m-d. Per-value `visibility` (App\Support\Visibility) is null when it should
 * fall back to the field default. See MemberProfileUpgrade for how OpenPNE 3 rows are flattened.
 */
#[Fillable(['member_id', 'profile_id', 'profile_option_id', 'value', 'value_datetime', 'visibility'])]
class MemberProfile extends Model
{
    /** @use HasFactory<MemberProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'value_datetime' => 'datetime',
            'visibility' => Visibility::class,
        ];
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'profile_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /** @return BelongsTo<ProfileOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ProfileOption::class, 'profile_option_id');
    }

    /**
     * Human-readable value for the current locale. Option fields resolve to the choice
     * label (preset choices from the catalog, custom from profile_options); dates format
     * the stored datetime. country/region rendering is added with their services later.
     */
    public function displayValue(string $lang = 'ja_JP'): string
    {
        $profile = $this->profile;
        $presets = app(PresetProfileService::class);

        if (in_array($profile->form_type, ['select', 'radio', 'checkbox'], true)) {
            if ($presets->usesValueColumnForChoice($profile)) {
                foreach ($presets->choicesFor($profile, $this->localeFor($lang)) as $choice) {
                    if ($choice['id'] === (string) $this->value) {
                        return $choice['caption'];
                    }
                }

                return (string) $this->value;
            }

            return $this->option?->getLabel($lang) ?? '';
        }

        if ($profile->form_type === 'date') {
            return $this->value_datetime?->format('Y-m-d') ?? (string) $this->value;
        }

        return (string) $this->value;
    }

    private function localeFor(string $translationLang): string
    {
        return $translationLang === 'ja_JP' ? 'ja' : 'en';
    }
}
