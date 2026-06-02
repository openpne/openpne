<?php

namespace Tests\Feature\Profile;

use Tests\TestCase;

/**
 * Preset captions/choices are translated with dynamic __($key) calls that i18n:check
 * cannot scan statically, so guard them here: every caption_key and choice label in the
 * catalog must have a lang/ja.json entry (en falls back to the key itself).
 */
class PresetTranslationKeysTest extends TestCase
{
    public function test_all_preset_caption_and_choice_keys_exist_in_ja_json(): void
    {
        $ja = json_decode((string) file_get_contents(base_path('lang/ja.json')), true);
        $this->assertIsArray($ja);

        $missing = [];
        foreach (config('preset_profile') as $def) {
            foreach ([$def['caption_key'] ?? null, ...array_values($def['choices'] ?? [])] as $key) {
                if ($key !== null && ! array_key_exists($key, $ja)) {
                    $missing[] = $key;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Missing ja.json keys for preset profile catalog.');
    }
}
