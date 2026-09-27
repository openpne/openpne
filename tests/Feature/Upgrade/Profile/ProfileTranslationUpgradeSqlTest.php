<?php

namespace Tests\Feature\Upgrade\Profile;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\ProfileTranslationUpgrade;
use App\Upgrade\Steps\ProfileUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

class ProfileTranslationUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL.');
        }

        foreach (['profile_translation', 'profile'] as $t) {
            DB::statement("DROP TABLE IF EXISTS `{$t}`");
        }
        DB::statement(SourceSchema::default()->createStatement('profile', withoutForeignKeys: true));
        DB::statement(SourceSchema::default()->createStatement('profile_translation', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (['profile_translation', 'profile'] as $t) {
                DB::statement("DROP TABLE IF EXISTS `{$t}`");
            }
        }

        parent::tearDown();
    }

    public function test_every_language_row_is_copied_verbatim_under_its_profile(): void
    {
        $this->seedProfile(1, 'custom_text');
        $this->seedProfile(2, 'op_preset_sex');
        // OpenPNE 3 stores Doctrine I18n cultures (ja_JP), which the profile screens look up as written.
        DB::table('profile_translation')->insert([
            ['id' => 1, 'lang' => 'ja_JP', 'caption' => '自己紹介', 'info' => '自由に書いてください'],
            ['id' => 1, 'lang' => 'en', 'caption' => 'About me', 'info' => null],
            ['id' => 2, 'lang' => 'ja_JP', 'caption' => '性別', 'info' => ''],
        ]);

        $compiler = new InsertSelectCompiler;
        DB::statement($compiler->compile(new ProfileUpgrade));
        DB::statement($compiler->compile(new ProfileTranslationUpgrade));

        $this->assertDatabaseCount('profile_translations', 3);
        $this->assertDatabaseHas('profile_translations', ['id' => 1, 'lang' => 'ja_JP', 'caption' => '自己紹介', 'info' => '自由に書いてください']);
        $this->assertDatabaseHas('profile_translations', ['id' => 1, 'lang' => 'en', 'caption' => 'About me', 'info' => null]);
        $this->assertDatabaseHas('profile_translations', ['id' => 2, 'lang' => 'ja_JP', 'caption' => '性別', 'info' => '']);
    }

    private function seedProfile(int $id, string $name): void
    {
        DB::table('profile')->insert([
            'id' => $id,
            'name' => $name,
            'form_type' => 'input',
            'value_type' => 'string',
            'default_public_flag' => 1,
            'created_at' => '2018-01-01 00:00:00',
            'updated_at' => '2018-01-01 00:00:00',
        ]);
    }
}
