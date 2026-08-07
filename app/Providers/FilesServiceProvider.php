<?php

namespace App\Providers;

use App\Files\DbBlobFileStorage;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Models\File;
use App\Observers\FileObserver;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Drivers\Vips\Driver;
use Intervention\Image\ImageManager;

class FilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound (not singleton) so each resolution reflects the current
        // openpne.files.disk; the implementations are stateless and cheap to build.
        $this->app->bind(FileStorage::class, function (): FileStorage {
            $disk = config('openpne.files.disk');

            // 'blob' is the DB-BLOB backend (not a Laravel filesystem disk). Any
            // other value names a config/filesystems.php disk served by DiskFileStorage.
            return $disk === 'blob'
                ? new DbBlobFileStorage
                : new DiskFileStorage($disk);
        });

        $this->app->singleton(ImageManager::class, function (): ImageManager {
            // gd (default) and imagick ship with intervention/image; vips additionally
            // needs the intervention/image-driver-vips package + the libvips system
            // library, and resolves with a clear error here if that is missing.
            $driver = match (config('openpne.images.driver')) {
                'imagick' => ImagickDriver::class,
                'vips' => Driver::class,
                default => \Intervention\Image\Drivers\Gd\Driver::class,
            };

            // Nothing here renders animation (see StillImageDecoder), and skipping the
            // frames keeps them from being allocated at all. Imagick is the exception:
            // intervention/image 4.2.0 drops the animation by emptying the Imagick object
            // the decoder then reads the media type from, so every GIF — animated or not —
            // fails to decode. StillImageDecoder collapses that driver's frames instead.
            return new ImageManager($driver, decodeAnimation: $driver === ImagickDriver::class);
        });
    }

    public function boot(): void
    {
        File::observe(FileObserver::class);
    }
}
