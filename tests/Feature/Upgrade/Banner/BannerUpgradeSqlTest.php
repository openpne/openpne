<?php

namespace Tests\Feature\Upgrade\Banner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\BannerImageUpgrade;
use App\Upgrade\Steps\BannerUpgrade;
use App\Upgrade\Steps\BannerUseImageUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled banner steps against the real OpenPNE 3 DDL: banner / banner_image / banner_use_image
 * copy verbatim, the banner's missing timestamps fall to their nullable default, and the placement pivot
 * resolves both foreign keys.
 *
 * MySQL only: the set-based copy and the source DDL are MySQL features.
 */
class BannerUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    private array $sourceTables = ['banner', 'banner_image', 'banner_use_image'];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        foreach ($this->sourceTables as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (array_reverse($this->sourceTables) as $table) {
                DB::statement("DROP TABLE IF EXISTS `{$table}`");
            }
        }

        parent::tearDown();
    }

    public function test_copies_banners_images_and_placement_links(): void
    {
        $this->seedFile(10);
        $this->seedFile(11);
        $this->seedBanner(1, 'top_before', isUseHtml: 0, html: null);
        $this->seedBanner(2, 'top_after', isUseHtml: 1, html: '<b>hi</b>');
        $this->seedBannerImage(100, fileId: 10, url: 'https://example.test', name: 'promo');
        $this->seedBannerImage(101, fileId: 11, url: null, name: null);
        $this->seedBannerUseImage(1000, bannerId: 1, bannerImageId: 100);
        $this->seedBannerUseImage(1001, bannerId: 1, bannerImageId: 101);

        $this->runUpgrade(new BannerUpgrade);
        $this->runUpgrade(new BannerImageUpgrade);
        $this->runUpgrade(new BannerUseImageUpgrade);

        // banner: copied; the absent OpenPNE 3 timestamps fall to the nullable default.
        $this->assertDatabaseHas('banners', ['id' => 1, 'name' => 'top_before', 'is_use_html' => 0, 'html' => null, 'created_at' => null]);
        $this->assertDatabaseHas('banners', ['id' => 2, 'name' => 'top_after', 'is_use_html' => 1, 'html' => '<b>hi</b>']);
        // banner_image: verbatim, including the nullable url / name.
        $this->assertDatabaseHas('banner_images', ['id' => 100, 'file_id' => 10, 'url' => 'https://example.test', 'name' => 'promo']);
        $this->assertDatabaseHas('banner_images', ['id' => 101, 'file_id' => 11, 'url' => null, 'name' => null]);
        // pivot: both foreign keys resolve.
        $this->assertDatabaseHas('banner_use_images', ['id' => 1000, 'banner_id' => 1, 'banner_image_id' => 100]);
        $this->assertDatabaseHas('banner_use_images', ['id' => 1001, 'banner_id' => 1, 'banner_image_id' => 101]);
    }

    private function runUpgrade(UpgradeStep $step): void
    {
        DB::statement((new InsertSelectCompiler)->compile($step));
    }

    private function seedFile(int $id): void
    {
        DB::table('files')->insert([
            'id' => $id,
            'name' => "tok_{$id}",
            'type' => 'image/png',
            'byte_size' => 128,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ]);
    }

    private function seedBanner(int $id, string $name, int $isUseHtml, ?string $html): void
    {
        DB::table('banner')->insert([
            'id' => $id,
            'name' => $name,
            'html' => $html,
            'is_use_html' => $isUseHtml,
        ]);
    }

    private function seedBannerImage(int $id, int $fileId, ?string $url, ?string $name): void
    {
        DB::table('banner_image')->insert([
            'id' => $id,
            'file_id' => $fileId,
            'url' => $url,
            'name' => $name,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ]);
    }

    private function seedBannerUseImage(int $id, int $bannerId, int $bannerImageId): void
    {
        DB::table('banner_use_image')->insert([
            'id' => $id,
            'banner_id' => $bannerId,
            'banner_image_id' => $bannerImageId,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ]);
    }
}
