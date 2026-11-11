<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OperatorContentMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_converts_what_an_existing_site_stored(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => 'footer_after'], ['value' => '<b>運営</b>']);
        Banner::create(['name' => 'top_after', 'is_use_html' => true, 'html' => '<i>ad</i>']);

        $this->migration()->up();

        $this->assertSame('**運営**', DB::table('sns_settings')->where('key', 'footer_after_markdown')->value('value'));
        $this->assertSame('*ad*', Banner::where('name', 'top_after')->value('markdown'));
    }

    public function test_a_failed_conversion_writes_nothing_and_the_rerun_completes(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => 'footer_after'], ['value' => '<b>運営</b>']);
        Banner::create(['name' => 'top_after', 'is_use_html' => true, 'html' => '<p>'.str_repeat('[', 40000).'</p>']);

        try {
            $this->migration()->up();
            $this->fail('the oversized rewrite should have thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('php artisan migrate', $e->getMessage());
        }
        $this->assertNull(DB::table('sns_settings')->where('key', 'footer_after_markdown')->value('value'));

        Banner::where('name', 'top_after')->update(['html' => '<i>ad</i>']);
        $this->migration()->up();

        $this->assertSame('**運営**', DB::table('sns_settings')->where('key', 'footer_after_markdown')->value('value'));
        $this->assertSame('*ad*', Banner::where('name', 'top_after')->value('markdown'));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_11_000002_convert_operator_html_to_markdown.php');
    }
}
