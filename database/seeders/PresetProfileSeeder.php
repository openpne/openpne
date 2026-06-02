<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Services\PresetProfileService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds OpenPNE 4's default member-profile field set on a fresh install (idempotent).
 *
 * This is OpenPNE 4's default set, not a replica of any one OpenPNE 3 install's initial
 * fixture: an upgraded site keeps its own fields (ProfileUpgrade brings them, and this
 * seeder is not run by `composer setup`, which only migrates). Country-specific region
 * fields are left to the operator to add in the admin — they share the unique
 * `op_preset_region` name, so only one variant (e.g. region_JP) can exist, and which one
 * is a per-site choice. default_public_flag is normalised to 1-4 (the catalog value is 0).
 */
class PresetProfileSeeder extends Seeder
{
    private const SEED_KEYS = ['sex', 'birthday', 'postal_code', 'telephone_number', 'self_introduction', 'country'];

    public function run(): void
    {
        $catalog = config('preset_profile', []);
        $order = 0;

        foreach (self::SEED_KEYS as $key) {
            $def = $catalog[$key] ?? null;
            $order += 10;
            if ($def === null) {
                continue;
            }

            $name = PresetProfileService::PREFIX.$key;
            if (Profile::query()->where('name', $name)->exists()) {
                continue;
            }

            $profile = Profile::query()->create([
                'name' => $name,
                'is_required' => $def['is_required'] ?? false,
                'is_unique' => false,
                'is_edit_public_flag' => $def['is_edit_public_flag'] ?? true,
                'default_public_flag' => PresetProfileService::normalizeDefaultPublicFlag($def['default_public_flag'] ?? 0),
                'form_type' => $def['form_type'],
                'value_type' => $def['value_type'] ?? 'string',
                'is_disp_regist' => $def['is_disp_regist'] ?? false,
                'is_disp_config' => $def['is_disp_config'] ?? false,
                'is_disp_search' => $def['is_disp_search'] ?? false,
                'is_public_web' => $def['is_public_web'] ?? false,
                'value_regexp' => $def['value_regexp'] ?? null,
                'sort_order' => $order,
            ]);

            foreach (['ja' => 'ja_JP', 'en' => 'en'] as $locale => $lang) {
                DB::table('profile_translations')->updateOrInsert(
                    ['id' => $profile->getKey(), 'lang' => $lang],
                    ['caption' => __($def['caption_key'], [], $locale), 'info' => null],
                );
            }
        }
    }
}
