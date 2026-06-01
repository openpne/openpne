<?php

namespace App\Providers;

use App\Files\DbBlobFileStorage;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Models\File;
use App\Observers\FileObserver;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Imagick\Driver;
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
                'imagick' => Driver::class,
                'vips' => \Intervention\Image\Drivers\Vips\Driver::class,
                default => \Intervention\Image\Drivers\Gd\Driver::class,
            };

            return ImageManager::usingDriver($driver);
        });
    }

    public function boot(): void
    {
        File::observe(FileObserver::class);
    }
}
