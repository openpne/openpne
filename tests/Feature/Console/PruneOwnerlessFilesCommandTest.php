<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneOwnerlessFilesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_ownerless_file_goes_with_its_bytes(): void
    {
        $file = File::factory()->create(['byte_size' => 1234]);
        DB::table('file_bin')->insert(['file_id' => $file->id, 'bin' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('openpne:prune-ownerless-files')
            ->expectsOutputToContain('Pruned 1 ownerless file(s), 1234 bytes.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        $this->assertDatabaseMissing('file_bin', ['file_id' => $file->id]);
    }

    public function test_an_owned_file_a_public_asset_and_an_openpne3_admin_image_are_kept(): void
    {
        $owned = File::factory()->create(['related_entity_type' => 'member', 'related_entity_id' => Member::factory()->create()->getKey()]);
        $public = File::factory()->create(['explicit_visibility' => File::VISIBILITY_PUBLIC]);
        // The OpenPNE 3 admin image manager's naming, carried verbatim by the upgrade.
        $adminImage = File::factory()->create(['name' => 'admin_logo_1300000000']);

        $this->artisan('openpne:prune-ownerless-files')
            ->expectsOutputToContain('No ownerless files to prune.')
            ->assertSuccessful();

        foreach ([$owned, $public, $adminImage] as $kept) {
            $this->assertDatabaseHas('files', ['id' => $kept->id]);
        }
    }

    public function test_a_dry_run_counts_rows_and_bytes_and_deletes_nothing(): void
    {
        File::factory()->create(['byte_size' => 1234]);
        File::factory()->create(['byte_size' => 6]);

        $this->artisan('openpne:prune-ownerless-files', ['--dry-run' => true])
            ->expectsOutputToContain('2 ownerless file(s), 1240 bytes, would be pruned.')
            ->assertSuccessful();

        $this->assertSame(2, File::count());
    }
}
