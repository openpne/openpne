<?php

/**
 * Catalog of OpenPNE preset profile fields, compatible with OpenPNE 3's preset_profile.yml.
 *
 * - The key is the preset identifier; the field registers under the profiles.name `op_preset_<key>`.
 * - caption_key is a string translated through __() (lang/ja.json carries the ja side).
 * - choices drives select/radio (key = stored value, value = display translation key). OpenPNE 3
 *   stores the choice's *value* side in member_profile.value (Female / Man for sex), so the key
 *   holds that. Preset choices come from this catalog; the profile_options table is unused.
 * - region_select follows OpenPNE 3's getRawPresetName(): region_JP / region_US and the rest all
 *   register under the same name=op_preset_region with value_type switching to JP / US, so the
 *   UNIQUE constraint admits only one of them at a time.
 * - default_public_flag is 0 in OpenPNE 3, outside its own 1-4 scale, so registration normalizes it
 *   to Visibility::Members (PresetProfileSeeder, ProfileUpgrade and admin-panel creation alike).
 */

return [
    'sex' => [
        'caption_key' => 'Sex',
        'form_type' => 'select',
        'value_type' => 'string',
        'is_disp_regist' => true,
        'is_disp_config' => true,
        'is_disp_search' => true,
        'is_required' => false,
        'is_edit_public_flag' => true,
        'default_public_flag' => 0,
        'choices' => [
            'Female' => 'Female',
            'Man' => 'Man',
        ],
    ],

    'birthday' => [
        'caption_key' => 'Birthday',
        'form_type' => 'date',
        'value_type' => 'string',
        'is_disp_regist' => true,
        'is_disp_config' => true,
        'is_disp_search' => true,
        'is_required' => false,
        'is_edit_public_flag' => true,
        'default_public_flag' => 0,
    ],

    'postal_code' => [
        'caption_key' => 'Postal Code',
        'form_type' => 'input',
        'value_type' => 'regexp',
        'value_regexp' => '/^\d{3}-\d{4}$/',
        'is_disp_regist' => true,
        'is_disp_config' => true,
        'is_disp_search' => true,
        'is_required' => false,
        'is_edit_public_flag' => true,
        'default_public_flag' => 0,
    ],

    'telephone_number' => [
        'caption_key' => 'Telephone Number',
        'form_type' => 'input',
        'value_type' => 'regexp',
        'value_regexp' => '/^[0-9\(\)\- ]+$/',
        'is_disp_regist' => true,
        'is_disp_config' => true,
        'is_disp_search' => true,
        'is_required' => false,
        'is_edit_public_flag' => true,
        'default_public_flag' => 0,
    ],

    'self_introduction' => [
        'caption_key' => 'Self Introduction',
        'form_type' => 'textarea',
        'value_type' => 'string',
        'is_disp_regist' => true,
        'is_disp_config' => true,
        'is_disp_search' => true,
        'is_required' => false,
        'is_edit_public_flag' => true,
        'default_public_flag' => 0,
    ],

    'country' => [
        'caption_key' => 'Country',
        'form_type' => 'country_select',
        'value_type' => 'string',
        'is_disp_regist' => true,
        'is_disp_config' => true,
        'is_disp_search' => true,
        'is_required' => false,
        'is_edit_public_flag' => true,
        'default_public_flag' => 0,
    ],

    'region' => [
        'caption_key' => 'Region',
        'form_type' => 'region_select',
        'value_type' => 'string',
        'is_disp_regist' => true,
        'is_disp_config' => true,
        'is_disp_search' => true,
        'is_required' => false,
        'is_edit_public_flag' => true,
        'default_public_flag' => 0,
    ],

    'region_JP' => ['caption_key' => 'Region in Japan', 'form_type' => 'region_select', 'value_type' => 'JP', 'is_disp_regist' => true, 'is_disp_config' => true, 'is_disp_search' => true, 'is_required' => false, 'is_edit_public_flag' => true, 'default_public_flag' => 0],
    'region_US' => ['caption_key' => 'Region in USA', 'form_type' => 'region_select', 'value_type' => 'US', 'is_disp_regist' => true, 'is_disp_config' => true, 'is_disp_search' => true, 'is_required' => false, 'is_edit_public_flag' => true, 'default_public_flag' => 0],
    'region_GB' => ['caption_key' => 'Region in UK', 'form_type' => 'region_select', 'value_type' => 'GB', 'is_disp_regist' => true, 'is_disp_config' => true, 'is_disp_search' => true, 'is_required' => false, 'is_edit_public_flag' => true, 'default_public_flag' => 0],
    'region_CA' => ['caption_key' => 'Region in Canada', 'form_type' => 'region_select', 'value_type' => 'CA', 'is_disp_regist' => true, 'is_disp_config' => true, 'is_disp_search' => true, 'is_required' => false, 'is_edit_public_flag' => true, 'default_public_flag' => 0],
    'region_DE' => ['caption_key' => 'Region in Germany', 'form_type' => 'region_select', 'value_type' => 'DE', 'is_disp_regist' => true, 'is_disp_config' => true, 'is_disp_search' => true, 'is_required' => false, 'is_edit_public_flag' => true, 'default_public_flag' => 0],
    'region_FR' => ['caption_key' => 'Region in France', 'form_type' => 'region_select', 'value_type' => 'FR', 'is_disp_regist' => true, 'is_disp_config' => true, 'is_disp_search' => true, 'is_required' => false, 'is_edit_public_flag' => true, 'default_public_flag' => 0],
    'region_IT' => ['caption_key' => 'Region in Italy', 'form_type' => 'region_select', 'value_type' => 'IT', 'is_disp_regist' => true, 'is_disp_config' => true, 'is_disp_search' => true, 'is_required' => false, 'is_edit_public_flag' => true, 'default_public_flag' => 0],
    'region_CN' => ['caption_key' => 'Region in China', 'form_type' => 'region_select', 'value_type' => 'CN', 'is_disp_regist' => true, 'is_disp_config' => true, 'is_disp_search' => true, 'is_required' => false, 'is_edit_public_flag' => true, 'default_public_flag' => 0],
];
