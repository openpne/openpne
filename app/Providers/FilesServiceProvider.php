<?php

namespace App\Providers;

use App\Files\DbBlobFileStorage;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Files\UploadLimit;
use App\Models\File;
use App\Observers\FileObserver;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use InvalidArgumentException;

class FilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Through config so a test can take either branch, and set unconditionally because
        // config:cache runs this too and would otherwise freeze the build host's answer.
        config(['openpne.images.exif' => extension_loaded('exif')]);

        // The admin forms upload through Livewire's temporary endpoint first, whose own rule
        // (12288 KB unconfigured) would otherwise be the real cap above that size.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.UploadLimit::kilobytes()]]);

        // Bound (not singleton) so each resolution reflects the current
        // openpne.files.disk; the implementations are stateless and cheap to build.
        $this->app->bind(FileStorage::class, function (): FileStorage {
            $disk = config('openpne.files.disk');

            // 'blob' is the DB-BLOB backend, not a filesystem disk name.
            return $disk === 'blob'
                ? new DbBlobFileStorage
                : new DiskFileStorage($disk);
        });

        $this->app->singleton(ImageManager::class, function (): ImageManager {
            // An unrecognised value throws rather than falling back to GD, whose colour handling
            // differs (GD cannot convert an embedded profile), so a typo never looks like it took effect.
            $driver = match ($configured = config('openpne.images.driver')) {
                'gd' => GdDriver::class,
                'imagick' => ImagickDriver::class,
                default => throw new InvalidArgumentException(
                    "Unsupported openpne.images.driver [{$configured}]; expected 'gd' or 'imagick'.",
                ),
            };

            // With decodeAnimation off, intervention/image 4.2.0 empties the Imagick object the decoder
            // reads the media type from and every GIF fails, so it stays on for Imagick and
            // StillImageDecoder collapses the frames instead.
            return new ImageManager($driver, decodeAnimation: $driver === ImagickDriver::class);
        });
    }

    public function boot(): void
    {
        File::observe(FileObserver::class);
    }
}
