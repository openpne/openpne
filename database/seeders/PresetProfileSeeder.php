<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Services\PresetProfileService;
use App\Support\Visibility;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * OpenPNE 4's own default set, not a replica of an OpenPNE 3 fixture: an upgraded site keeps its
 * own fields, which ProfileUpgrade brings. The rest of the catalog is the operator's to add
 * (docs/internals/member-profile.md, "Field definitions vs values").
 */
class PresetProfileSeeder extends Seeder
{
    private const SEED_KEYS = ['birthday', 'self_introduction'];

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
                'default_visibility' => Visibility::fromOpenPne3PublicFlag($def['default_public_flag'] ?? 0),
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
