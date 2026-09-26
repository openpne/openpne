<?php

namespace Tests\Feature\Upgrade\Profile;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\ProfileOptionTranslationUpgrade;
use App\Upgrade\Steps\ProfileOptionUpgrade;
use App\Upgrade\Steps\ProfileUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

/** MySQL only, like every INSERT...SELECT step; the profile and option steps run first so the translation FK resolves. */
class ProfileOptionTranslationUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL.');
        }

        foreach (['profile_option_translation', 'profile_option', 'profile'] as $t) {
            DB::statement("DROP TABLE IF EXISTS `{$t}`");
        }
        DB::statement(SourceSchema::default()->createStatement('profile', withoutForeignKeys: true));
        DB::statement(SourceSchema::default()->createStatement('profile_option', withoutForeignKeys: true));
        DB::statement(SourceSchema::default()->createStatement('profile_option_translation', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (['profile_option_translation', 'profile_option', 'profile'] as $t) {
                DB::statement("DROP TABLE IF EXISTS `{$t}`");
            }
        }

        parent::tearDown();
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
        DB::table('profile_option_translation')->insert([
            ['id' => 50, 'lang' => 'ja', 'value' => 'はい'],
            ['id' => 50, 'lang' => 'en', 'value' => 'Yes'],
            ['id' => 51, 'lang' => 'ja', 'value' => null],
        ]);

        $compiler = new InsertSelectCompiler;
        DB::statement($compiler->compile(new ProfileUpgrade));
        DB::statement($compiler->compile(new ProfileOptionUpgrade));
        DB::statement($compiler->compile(new ProfileOptionTranslationUpgrade));

        $this->assertDatabaseCount('profile_option_translations', 3);
        $this->assertDatabaseHas('profile_option_translations', ['id' => 50, 'lang' => 'ja', 'value' => 'はい']);
        $this->assertDatabaseHas('profile_option_translations', ['id' => 50, 'lang' => 'en', 'value' => 'Yes']);
        $this->assertDatabaseHas('profile_option_translations', ['id' => 51, 'lang' => 'ja', 'value' => null]);
    }
}
