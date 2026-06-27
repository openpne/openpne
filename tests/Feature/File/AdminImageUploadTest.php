<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The admin image upload path. The Filament FileUpload field cannot be driven through a Livewire test
 * (no real temp upload), so the byte + visibility behaviour is exercised at the FileUploader seam the
 * page delegates to — a fake UploadedFile is a real file FileUploader can read.
 */
class AdminImageUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_an_ownerless_public_image_servable_to_guests(): void
    {
        $upload = UploadedFile::fake()->image('promo.png', 120, 80);

        $file = app(FileUploader::class)->store($upload, explicitVisibility: File::VISIBILITY_PUBLIC);

        // Ownerless, marked public, bytes stored.
        $this->assertNull($file->related_entity_type);
        $this->assertNull($file->related_entity_id);
        $this->assertSame(File::VISIBILITY_PUBLIC, $file->explicit_visibility);
        $this->assertGreaterThan(0, $file->byte_size);
        $this->assertTrue(app(FileStorage::class)->exists($file));

        // The whole point: a guest (and any member) may fetch it.
        $this->assertTrue(Gate::forUser(null)->allows('view', $file));
        $this->assertTrue(Gate::forUser(Member::factory()->create())->allows('view', $file));
    }

    public function test_default_store_leaves_visibility_inheriting_and_fail_closed(): void
    {
        $upload = UploadedFile::fake()->image('orphan.png');

        // No explicit visibility and no owner → fail-closed (the existing contract is unchanged).
        $file = app(FileUploader::class)->store($upload);

        $this->assertNull($file->explicit_visibility);
        $this->assertFalse(Gate::forUser(Member::factory()->create())->allows('view', $file));
    }
}
