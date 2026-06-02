<?php

namespace App\Services;

use App\Models\Profile;

/**
 * Resolves the preset profile catalog (config/preset_profile.php).
 *
 * Preset fields (profiles.name starting with `op_preset_`) take their structure and
 * choices from the catalog rather than the database: select/radio choices come from the
 * catalog and the chosen key is stored directly in member_profiles.value (no
 * profile_option row). Custom fields use profile_options instead.
 */
class PresetProfileService
{
    public const PREFIX = 'op_preset_';

    /** @return array<string, array<string, mixed>> */
    public function getAll(): array
    {
        return config('preset_profile', []);
    }

    /** @return array<string, mixed>|null */
    public function findByKey(string $key): ?array
    {
        return $this->getAll()[$key] ?? null;
    }

    /**
     * The catalog entry for a registered preset profile, resolving the OpenPNE
     * getRawPresetName() rule (region_select with a non-string value_type keys on
     * `region_<value_type>`). Null for custom fields.
     *
     * @return array<string, mixed>|null
     */
    public function findFor(Profile $profile): ?array
    {
        if (! $profile->isPreset()) {
            return null;
        }

        return $this->getAll()[$this->rawPresetName($profile)] ?? null;
    }

    /**
     * Whether the field's choices come from the catalog (and the chosen key lives in
     * member_profiles.value). True only for preset select/radio with catalog choices.
     */
    public function usesValueColumnForChoice(Profile $profile): bool
    {
        if (! in_array($profile->form_type, ['select', 'radio'], true)) {
            return false;
        }

        $preset = $this->findFor($profile);

        return ! empty($preset['choices']);
    }

    /**
     * Choices for a preset select/radio as [['id' => key, 'caption' => label], ...].
     * Empty for custom fields (their choices are profile_options rows).
     *
     * @return list<array{id: string, caption: string}>
     */
    public function choicesFor(Profile $profile, string $locale = 'ja'): array
    {
        $preset = $this->findFor($profile);
        if (empty($preset['choices'])) {
            return [];
        }

        $choices = [];
        foreach ($preset['choices'] as $key => $captionKey) {
            $choices[] = ['id' => (string) $key, 'caption' => __($captionKey, [], $locale)];
        }

        return $choices;
    }

    /**
     * Preset keys not yet registered as a profile, for the admin "add preset" picker.
     * All region_* variants are hidden once any `op_preset_region` exists (they share the
     * unique name). Returns [catalogKey => translated caption].
     *
     * @return array<string, string>
     */
    public function unregisteredOptions(string $locale = 'ja'): array
    {
        $existing = Profile::query()->pluck('name')->all();
        $regionTaken = in_array(self::PREFIX.'region', $existing, true);

        $result = [];
        foreach ($this->getAll() as $key => $def) {
            $name = $this->nameForKey($key)['name'];
            if (in_array($name, $existing, true)) {
                continue;
            }
            if ($regionTaken && $name === self::PREFIX.'region') {
                continue;
            }
            $result[$key] = __($def['caption_key'] ?? $key, [], $locale);
        }

        return $result;
    }

    /**
     * The profiles.name + value_type a catalog key registers as. region_JP etc. collapse
     * to name `op_preset_region` with value_type `JP`.
     *
     * @return array{name: string, value_type: string}
     */
    public function nameForKey(string $key): array
    {
        $def = $this->findByKey($key) ?? [];
        $formType = $def['form_type'] ?? '';
        $valueType = (string) ($def['value_type'] ?? 'string');

        if ($formType === 'region_select' && $valueType !== 'string') {
            return ['name' => self::PREFIX.'region', 'value_type' => $valueType];
        }

        return ['name' => self::PREFIX.$key, 'value_type' => $valueType];
    }

    /** Normalise OpenPNE's default_public_flag (0/invalid) to a valid 1-4 value (0 → SNS). */
    public static function normalizeDefaultPublicFlag(int|string|null $flag): int
    {
        $flag = (int) $flag;

        return in_array($flag, [1, 2, 3, 4], true) ? $flag : Profile::PUBLIC_FLAG_SNS;
    }

    private function rawPresetName(Profile $profile): string
    {
        $name = substr($profile->name, strlen(self::PREFIX));

        if ($profile->form_type === 'region_select' && $profile->value_type !== 'string') {
            $name .= '_'.$profile->value_type;
        }

        return $name;
    }
}
