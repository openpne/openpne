<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\File;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneOwnerlessFilesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_aged_ownerless_file_goes_with_its_bytes(): void
    {
        $file = $this->agedFile();
        DB::table('file_bin')->insert(['file_id' => $file->id, 'bin' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('openpne:prune-ownerless-files')
            ->expectsOutputToContain('Pruned 1 ownerless file(s), 1234 bytes.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        $this->assertDatabaseMissing('file_bin', ['file_id' => $file->id]);
    }

    public function test_an_owned_file_a_public_asset_and_a_recent_upload_are_kept(): void
    {
        $owned = $this->agedFile(['related_entity_type' => 'member', 'related_entity_id' => Member::factory()->create()->getKey()]);
        $public = $this->agedFile(['explicit_visibility' => File::VISIBILITY_PUBLIC]);
        $recent = File::factory()->create(['byte_size' => 10]);

        $this->artisan('openpne:prune-ownerless-files')
            ->expectsOutputToContain('No ownerless files to prune.')
            ->assertSuccessful();

        foreach ([$owned, $public, $recent] as $kept) {
            $this->assertDatabaseHas('files', ['id' => $kept->id]);
        }
    }

    public function test_a_dry_run_counts_rows_and_bytes_and_deletes_nothing(): void
    {
        $this->agedFile();
        $this->agedFile(['byte_size' => 6]);

        $this->artisan('openpne:prune-ownerless-files', ['--dry-run' => true])
            ->expectsOutputToContain('2 ownerless file(s), 1240 bytes, would be pruned.')
            ->assertSuccessful();

        $this->assertSame(2, File::count());
    }

    public function test_the_grace_period_follows_the_days_option(): void
    {
        $file = File::factory()->create(['created_at' => CarbonImmutable::now()->subDays(2)]);

        $this->artisan('openpne:prune-ownerless-files', ['--days' => 1])->assertSuccessful();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function agedFile(array $attributes = []): File
    {
        return File::factory()->create($attributes + ['byte_size' => 1234, 'created_at' => CarbonImmutable::now()->subDays(30)]);
    }
}
