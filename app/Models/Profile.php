<?php

namespace App\Models;

use App\Services\PresetProfileService;
use App\Support\Visibility;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A member-profile field definition (OpenPNE 3 `profile`).
 *
 * Captions/info are localised in `profile_translations` keyed by (id, lang); preset fields
 * fall back to the config/preset_profile.php caption via __(). `op_preset_*` fields source
 * their choices from the catalog, custom fields from `profile_options`.
 */
#[Fillable(['name', 'is_required', 'is_unique', 'is_edit_public_flag', 'default_visibility', 'form_type', 'value_type', 'is_disp_regist', 'is_disp_config', 'is_disp_search', 'is_public_web', 'value_regexp', 'value_min', 'value_max', 'sort_order'])]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    /** Translation lang code (OpenPNE/Doctrine I18n) for each app locale. */
    private const TRANSLATION_LANG = ['ja' => 'ja_JP', 'en' => 'en'];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_unique' => 'boolean',
            'is_edit_public_flag' => 'boolean',
            'default_visibility' => Visibility::class,
            'is_disp_regist' => 'boolean',
            'is_disp_config' => 'boolean',
            'is_disp_search' => 'boolean',
            'is_public_web' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<ProfileTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(ProfileTranslation::class, 'id', 'id');
    }

    /** @return HasMany<ProfileOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ProfileOption::class, 'profile_id')->orderBy('sort_order');
    }

    public function isPreset(): bool
    {
        return str_starts_with($this->name, PresetProfileService::PREFIX);
    }

    /**
     * The per-value visibility choices offered when editing this field, when is_edit_public_flag
     * is set. Open (guest-visible) is offered only for a web-public field, matching OpenPNE 3's
     * profile editor which hid "Public to Web" unless the field allowed it.
     *
     * @return list<Visibility>
     */
    public function visibilityOptions(): array
    {
        $options = [Visibility::Members, Visibility::Friends, Visibility::Private];

        if ($this->is_public_web) {
            array_unshift($options, Visibility::Open);
        }

        return $options;
    }

    /**
     * OpenPNE 3 Profile::isMultipleSelect(): a custom date (year/month/day) or a checkbox.
     * Preset date (birthday) is a single value.
     */
    public function isMultipleSelect(): bool
    {
        return ($this->form_type === 'date' && ! $this->isPreset()) || $this->form_type === 'checkbox';
    }

    /**
     * Selectable choices for a select/radio/checkbox field as [['id' => ..., 'caption' => ...]].
     * A preset takes catalog choices (the key is stored in member_profiles.value); a custom field
     * uses its profile_options (the option id is stored). Empty for non-option fields.
     *
     * @return list<array{id: string, caption: string}>
     */
    public function choices(string $lang = 'ja_JP'): array
    {
        if (! in_array($this->form_type, ['select', 'radio', 'checkbox'], true)) {
            return [];
        }

        $presets = app(PresetProfileService::class);
        if ($presets->usesValueColumnForChoice($this)) {
            return $presets->choicesFor($this, $lang === 'ja_JP' ? 'ja' : 'en');
        }

        return $this->options->map(fn (ProfileOption $option): array => [
            'id' => (string) $option->getKey(),
            'caption' => $option->getLabel($lang),
        ])->all();
    }

    public function getCaption(string $lang = 'ja_JP'): string
    {
        $caption = $this->translations->firstWhere('lang', $lang)?->caption;
        if (is_string($caption) && $caption !== '') {
            return $caption;
        }

        if ($this->isPreset()) {
            $preset = app(PresetProfileService::class)->findFor($this);
            if (! empty($preset['caption_key'])) {
                return __($preset['caption_key'], [], self::appLocale($lang));
            }
        }

        return $this->name;
    }

    public function getInfo(string $lang = 'ja_JP'): ?string
    {
        return $this->translations->firstWhere('lang', $lang)?->info;
    }

    private static function appLocale(string $translationLang): string
    {
        return array_search($translationLang, self::TRANSLATION_LANG, true) ?: app()->getLocale();
    }
}
