<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Files\Pages\ListFiles;
use App\Files\FileStorage;
use App\Models\AdminUser;
use App\Models\File;
use App\Models\Member;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FileResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    /** Create a File with real stored bytes via the storage seam. */
    private function fileWithBytes(string $content = 'data', array $attributes = []): File
    {
        $file = File::factory()->create($attributes + ['byte_size' => strlen($content)]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return $file;
    }

    public function test_upload_image_header_action_is_available(): void
    {
        // The FileUpload field needs a real Livewire temp upload the test harness can't drive, so this
        // asserts the surface is wired; the byte+visibility path is covered at the FileUploader seam
        // (Tests\Feature\File\AdminImageUploadTest) and the shape extraction by FormUploadTest.
        Livewire::test(ListFiles::class)
            ->assertActionExists('uploadImage');
    }

    public function test_list_page_renders_files(): void
    {
        $files = File::factory()->count(2)->create();

        Livewire::test(ListFiles::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($files);
    }

    public function test_owner_type_filter(): void
    {
        $diaryFile = File::factory()->create(['related_entity_type' => 'diary', 'related_entity_id' => 1]);
        $memberFile = File::factory()->create(['related_entity_type' => 'member', 'related_entity_id' => 1]);

        Livewire::test(ListFiles::class)
            ->filterTable('related_entity_type', 'diary')
            ->assertCanSeeTableRecords([$diaryFile])
            ->assertCanNotSeeTableRecords([$memberFile]);
    }

    public function test_admin_can_fetch_file_bytes_regardless_of_owner(): void
    {
        // A private diary's image would be denied to members by FilePolicy; the admin path serves it.
        $file = $this->fileWithBytes('secret-bytes', [
            'type' => 'image/png',
            'related_entity_type' => 'diary',
            'related_entity_id' => 999,
        ]);

        $response = $this->get(route('admin.file.raw', ['file' => $file->name]));

        $response->assertOk();
        $this->assertSame('secret-bytes', $response->streamedContent());
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_guest_cannot_fetch_file_bytes(): void
    {
        $file = $this->fileWithBytes();

        auth('admin')->logout();

        $this->get(route('admin.file.raw', ['file' => $file->name]))->assertNotFound();
    }

    public function test_logged_in_member_cannot_fetch_file_bytes(): void
    {
        // The security boundary is member != admin: a member authenticated on the `member`
        // guard must not satisfy the admin-guard check, so the byte path stays admin-only.
        $file = $this->fileWithBytes();

        auth('admin')->logout();
        $this->actingAs(Member::factory()->create(), 'member');

        $this->get(route('admin.file.raw', ['file' => $file->name]))->assertNotFound();
    }

    public function test_delete_purges_stored_bytes(): void
    {
        $file = $this->fileWithBytes();
        $storage = app(FileStorage::class);
        $this->assertTrue($storage->exists($file));

        Livewire::test(ListFiles::class)
            ->callAction(TestAction::make('delete')->table($file));

        $this->assertModelMissing($file);
        $this->assertFalse($storage->exists($file));
    }
}
