<?php

namespace App\Providers;

use App\Files\DbBlobFileStorage;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
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
        // Whether EXIF Orientation can be read is a host fact, but the variant key reads it from
        // config so that a test can take either branch. Set unconditionally: config:cache runs this
        // too and would otherwise freeze the build host's answer for every request.
        config(['openpne.images.exif' => extension_loaded('exif')]);

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
            // Both ship with intervention/image. imagick is the one worth choosing
            // deliberately: unlike GD it can convert an embedded colour profile.
            //
            // An unrecognised value throws rather than falling back to GD: a deployment
            // that asked for a driver it does not get would run with different colour
            // handling and never be told — a leftover `vips`, or a typo, would look like
            // it took effect.
            $driver = match ($configured = config('openpne.images.driver')) {
                'gd' => GdDriver::class,
                'imagick' => ImagickDriver::class,
                default => throw new InvalidArgumentException(
                    "Unsupported openpne.images.driver [{$configured}]; expected 'gd' or 'imagick'.",
                ),
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
