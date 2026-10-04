<?php

namespace Tests\Feature\Upgrade\Profile;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\ProfileOptionTranslationUpgrade;
use App\Upgrade\Steps\ProfileOptionUpgrade;
use App\Upgrade\Steps\ProfileUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

class ProfileOptionTranslationUpgradeSqlTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        return ['profile', 'profile_option', 'profile_option_translation'];
    }

    public function test_every_language_row_is_copied_verbatim_under_its_option(): void
    {
        DB::table('profile')->insert([
            'id' => 3,
            'name' => 'custom_sel',
            'form_type' => 'select',
            'value_type' => 'string',
            'default_public_flag' => 1,
            'created_at' => '2018-01-01 00:00:00',
            'updated_at' => '2018-01-01 00:00:00',
        ]);
        DB::table('profile_option')->insert([
            ['id' => 50, 'profile_id' => 3, 'sort_order' => 0, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00'],
            ['id' => 51, 'profile_id' => 3, 'sort_order' => 1, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00'],
        ]);
        // OpenPNE 3 stores Doctrine I18n cultures (ja_JP), which the profile screens look up as written.
        DB::table('profile_option_translation')->insert([
            ['id' => 50, 'lang' => 'ja_JP', 'value' => 'はい'],
            ['id' => 50, 'lang' => 'en', 'value' => 'Yes'],
            ['id' => 51, 'lang' => 'ja_JP', 'value' => null],
            ['id' => 51, 'lang' => 'en', 'value' => ''],
        ]);

        $compiler = new InsertSelectCompiler;
        DB::statement($compiler->compile(new ProfileUpgrade));
        DB::statement($compiler->compile(new ProfileOptionUpgrade));
        DB::statement($compiler->compile(new ProfileOptionTranslationUpgrade));

        $this->assertDatabaseCount('profile_option_translations', 4);
        $this->assertDatabaseHas('profile_option_translations', ['id' => 50, 'lang' => 'ja_JP', 'value' => 'はい']);
        $this->assertDatabaseHas('profile_option_translations', ['id' => 50, 'lang' => 'en', 'value' => 'Yes']);
        $this->assertDatabaseHas('profile_option_translations', ['id' => 51, 'lang' => 'ja_JP', 'value' => null]);
        $this->assertDatabaseHas('profile_option_translations', ['id' => 51, 'lang' => 'en', 'value' => '']);
    }
}
