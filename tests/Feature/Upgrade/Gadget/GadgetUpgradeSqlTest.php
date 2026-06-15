<?php

namespace Tests\Feature\Upgrade\Gadget;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\GadgetConfigUpgrade;
use App\Upgrade\Steps\GadgetUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled gadget INSERT...SELECT against the real OpenPNE 3 `gadget` + `gadget_config` DDL,
 * exercising the type→(context, zone) split, the PC-context keep filter, and the config-row scoping.
 *
 * MySQL only, like the other upgrade SQL tests (source DDL + set-based copy are MySQL features).
 */
class GadgetUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        $this->createSourceTables();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `gadget_config`');
            DB::statement('DROP TABLE IF EXISTS `gadget`');
        }

        parent::tearDown();
    }

    public function test_splits_the_type_into_context_and_zone_keeping_the_original(): void
    {
        $this->seedGadget(1, 'top', 'informationBox');
        $this->seedGadget(2, 'profileSideMenu', 'friendListBox');
        $this->seedGadget(3, 'loginContents', 'loginForm');
        $this->seedGadget(4, 'sideBannerContents', 'languageSelecterBox');

        $this->runUpgrade();

        $this->assertDatabaseHas('gadgets', ['id' => 1, 'context' => 'home', 'zone' => 'top', 'source_type' => 'top']);
        $this->assertDatabaseHas('gadgets', ['id' => 2, 'context' => 'profile', 'zone' => 'sideMenu', 'source_type' => 'profileSideMenu']);
        $this->assertDatabaseHas('gadgets', ['id' => 3, 'context' => 'login', 'zone' => 'contents', 'source_type' => 'loginContents']);
        $this->assertDatabaseHas('gadgets', ['id' => 4, 'context' => 'sidebanner', 'zone' => 'contents', 'source_type' => 'sideBannerContents']);
    }

    public function test_drops_mobile_and_smartphone_and_dailynews_types(): void
    {
        $this->seedGadget(1, 'top', 'informationBox');
        $this->seedGadget(2, 'mobileTop', 'informationBox');
        $this->seedGadget(3, 'smartphoneContents', 'informationBox');

        $this->runUpgrade();

        $this->assertDatabaseHas('gadgets', ['id' => 1]);
        $this->assertDatabaseMissing('gadgets', ['id' => 2]);
        $this->assertDatabaseMissing('gadgets', ['id' => 3]);
    }

    public function test_preserves_sparse_ids(): void
    {
        $this->seedGadget(7, 'contents', 'searchBox');
        $this->seedGadget(42, 'top', 'informationBox');

        $this->runUpgrade();

        $this->assertDatabaseHas('gadgets', ['id' => 7]);
        $this->assertDatabaseHas('gadgets', ['id' => 42]);
    }

    public function test_migrates_config_only_for_kept_gadgets(): void
    {
        $this->seedGadget(1, 'contents', 'freeArea');     // kept (home)
        $this->seedGadget(2, 'mobileContents', 'freeArea'); // dropped
        $this->seedConfig(10, 1, 'title', 'Hello');
        $this->seedConfig(20, 2, 'title', 'Mobile');

        $this->runUpgrade();

        $this->assertDatabaseHas('gadget_configs', ['gadget_id' => 1, 'name' => 'title', 'value' => 'Hello']);
        $this->assertDatabaseMissing('gadget_configs', ['gadget_id' => 2]);
    }

    private function createSourceTables(): void
    {
        DB::statement('DROP TABLE IF EXISTS `gadget_config`');
        DB::statement('DROP TABLE IF EXISTS `gadget`');
        DB::statement(SourceSchema::default()->createStatement('gadget', withoutForeignKeys: true));
        DB::statement(SourceSchema::default()->createStatement('gadget_config', withoutForeignKeys: true));
    }

    private function runUpgrade(): void
    {
        $compiler = new InsertSelectCompiler;
        DB::statement($compiler->compile(new GadgetUpgrade));
        DB::statement($compiler->compile(new GadgetConfigUpgrade));
    }

    private function seedGadget(int $id, string $type, string $name, ?int $sort = 0): void
    {
        DB::table('gadget')->insert([
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'sort_order' => $sort,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    private function seedConfig(int $id, int $gadgetId, string $name, string $value): void
    {
        DB::table('gadget_config')->insert([
            'id' => $id,
            'gadget_id' => $gadgetId,
            'name' => $name,
            'value' => $value,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }
}
