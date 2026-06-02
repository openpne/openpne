<?php

/**
 * Catalog of OpenPNE preset profile fields (OpenPNE 3 preset_profile.yml 互換).
 *
 * - キーは preset 識別子。`op_preset_<key>` という profiles.name で登録される。
 * - caption_key は __() で翻訳される文字列(lang/ja.json に対応訳を置く)。
 * - choices は select/radio 用(key=保存値、value=表示翻訳キー)。preset の選択肢は
 *   この catalog から出し、profile_options テーブルは使わない。
 * - region_select は OpenPNE の getRawPresetName() 互換: region_JP / region_US 等は
 *   同じ name=op_preset_region で value_type が JP / US に切り替わる(UNIQUE 制約のため
 *   同時に 1 つだけ登録可)。
 * - default_public_flag は OpenPNE では 0 だが 1-4 でないため、登録時に 1(SNS)へ
 *   正規化する(PresetProfileSeeder / ProfileUpgrade / 管理画面作成のいずれも)。
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
            'F' => 'Female',
            'M' => 'Male',
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
