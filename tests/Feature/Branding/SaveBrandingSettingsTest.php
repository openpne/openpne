<?php

declare(strict_types=1);

namespace Tests\Feature\Branding;

use App\Features\Branding\Actions\SaveBrandingSettings;
use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Models\File;
use App\Support\SnsSettingKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * The Filament FileUpload field cannot be driven through a Livewire test (no real temp upload), so
 * the file behaviour is exercised at the action the page delegates to.
 */
class SaveBrandingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_second_upload_takes_the_first_one_with_it(): void
    {
        $this->failUploadsAfterTheFirst();

        try {
            app(SaveBrandingSettings::class)('#2563eb', [
                SnsSettingKey::BrandLogoFile->value => $this->png(),
                SnsSettingKey::BrandFaviconFile->value => $this->png('icon.png'),
            ]);
            $this->fail('The failing upload should have propagated.');
        } catch (RuntimeException $e) {
            $this->assertSame('storage is down', $e->getMessage());
        }

        $this->assertSame(0, File::count(), 'The file stored before the failure is compensated away.');
        $this->assertDatabaseMissing('sns_settings', ['key' => SnsSettingKey::BrandColor->value]);
    }

    public function test_a_failed_settings_write_takes_every_new_file_with_it(): void
    {
        $upload = $this->png();
        $this->failSettingsWrites();

        try {
            app(SaveBrandingSettings::class)('#2563eb', [SnsSettingKey::BrandLogoFile->value => $upload]);
            $this->fail('The failing settings write should have propagated.');
        } catch (RuntimeException $e) {
            $this->assertSame('settings write failed', $e->getMessage());
        }

        $this->assertSame(0, File::count(), 'Every file stored in this save is compensated away.');
        $this->assertDatabaseMissing('sns_settings', ['key' => SnsSettingKey::BrandLogoFile->value]);
    }

    public function test_a_replacement_stores_the_new_token_and_purges_the_old_file(): void
    {
        $old = $this->storeCurrent(SnsSettingKey::BrandLogoFile);

        app(SaveBrandingSettings::class)('#0088aa', [SnsSettingKey::BrandLogoFile->value => $this->png('new.png')]);

        $this->assertNull(File::find($old->getKey()), 'The replaced file row is gone.');
        $this->assertFalse(app(FileStorage::class)->exists($old), 'The replaced bytes are gone.');

        $token = $this->storedValue(SnsSettingKey::BrandLogoFile);
        $this->assertNotSame('', $token);
        $this->assertSame($token, File::sole()->name);
        $this->assertSame('#0088aa', $this->storedValue(SnsSettingKey::BrandColor));
    }

    public function test_a_removal_clears_the_setting_and_deletes_the_file(): void
    {
        $old = $this->storeCurrent(SnsSettingKey::BrandFaviconFile);

        app(SaveBrandingSettings::class)('', [SnsSettingKey::BrandFaviconFile->value => null]);

        $this->assertSame('', $this->storedValue(SnsSettingKey::BrandFaviconFile));
        $this->assertNull(File::find($old->getKey()));
        $this->assertFalse(app(FileStorage::class)->exists($old));
    }

    public function test_an_untouched_file_setting_keeps_its_token(): void
    {
        $current = $this->storeCurrent(SnsSettingKey::BrandLogoFile);

        app(SaveBrandingSettings::class)('#2563eb');

        $this->assertSame($current->name, $this->storedValue(SnsSettingKey::BrandLogoFile));
        $this->assertNotNull(File::find($current->getKey()));
        // Save-all: every branding key gets a row, the blank ones included.
        $this->assertSame('', $this->storedValue(SnsSettingKey::BrandFaviconFile));
    }

    public function test_an_unknown_file_key_is_rejected_before_anything_is_stored(): void
    {
        try {
            app(SaveBrandingSettings::class)('', ['sns_name' => $this->png()]);
            $this->fail('The unknown key should have been rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sns_name', $e->getMessage());
        }

        $this->assertSame(0, File::count());
        $this->assertDatabaseMissing('sns_settings', ['key' => SnsSettingKey::BrandColor->value]);
    }

    public function test_a_superseded_token_pointing_at_an_owned_file_is_left_alone(): void
    {
        // A corrupted setting must not take someone else's file down with it: only the ownerless
        // public assets this page uploads are purged.
        $owned = app(FileUploader::class)->store($this->png('avatar.png'), 'memberImage', 1);
        $this->setSnsSetting(SnsSettingKey::BrandLogoFile, $owned->name);

        app(SaveBrandingSettings::class)('', [SnsSettingKey::BrandLogoFile->value => null]);

        $this->assertSame('', $this->storedValue(SnsSettingKey::BrandLogoFile));
        $this->assertNotNull(File::find($owned->getKey()));
        $this->assertTrue(app(FileStorage::class)->exists($owned));
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 64, 64);
    }

    private function storedValue(SnsSettingKey $key): string
    {
        return (string) DB::table('sns_settings')->where('key', $key->value)->value('value');
    }

    /** An ownerless public asset the action will find as the current file for $key. */
    private function storeCurrent(SnsSettingKey $key): File
    {
        $file = app(FileUploader::class)->store($this->png('old.png'), explicitVisibility: File::VISIBILITY_PUBLIC);
        $this->setSnsSetting($key, $file->name);

        return $file;
    }

    /** Store the first upload for real, then fail — so the compensation has a real row + bytes to undo. */
    private function failUploadsAfterTheFirst(): void
    {
        $real = app(FileUploader::class);

        $this->app->instance(FileUploader::class, new class($real) extends FileUploader
        {
            private int $calls = 0;

            public function __construct(private readonly FileUploader $inner) {}

            public function store(UploadedFile $upload, ?string $relatedType = null, ?int $relatedId = null, ?string $explicitVisibility = null): File
            {
                if (++$this->calls > 1) {
                    throw new RuntimeException('storage is down');
                }

                return $this->inner->store($upload, $relatedType, $relatedId, $explicitVisibility);
            }
        });
    }

    /** Break the settings write itself (the row goes in, then the transaction throws and rolls back). */
    private function failSettingsWrites(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'sns_settings') && str_starts_with($query->sql, 'insert')) {
                throw new RuntimeException('settings write failed');
            }
        });
    }
}
