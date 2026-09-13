<?php

namespace App\Providers;

use App\Files\DbBlobFileStorage;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Files\GdImageProcessor;
use App\Files\ImageProcessor;
use App\Files\Imgproxy\ImgproxyImageProcessor;
use App\Files\Imgproxy\ImgproxyUrl;
use App\Files\UploadLimit;
use App\Models\File;
use App\Observers\FileObserver;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use InvalidArgumentException;

class FilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Through config so a test can take either branch, and set unconditionally because
        // config:cache runs this too and would otherwise freeze the build host's answer.
        config(['openpne.images.exif' => extension_loaded('exif')]);

        self::refuseRemovedSettings();

        // Refused at boot rather than on the first picture, whose callers expect the seam's two exceptions.
        if (config('openpne.images.processor') === 'imgproxy') {
            ImgproxyUrl::fromConfig((array) config('openpne.images.imgproxy'));
        }

        // Livewire's own temporary-upload rule (12288 KB) would otherwise cap the admin forms above
        // it, and setting it after the package's shallow mergeConfigFrom keeps the sibling keys.
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

        $this->app->singleton(ImageProcessor::class, function (): ImageProcessor {
            // An unrecognised value throws rather than falling back to GD, so a typo never looks like
            // it took effect.
            return match ($configured = config('openpne.images.processor')) {
                'gd' => new GdImageProcessor(new ImageManager(GdDriver::class, decodeAnimation: false)),
                'imgproxy' => ImgproxyImageProcessor::fromConfig(),
                default => throw new InvalidArgumentException(
                    "Unsupported openpne.images.processor [{$configured}]; expected 'gd' or 'imgproxy'.",
                ),
            };
        });
    }

    public function boot(): void
    {
        File::observe(FileObserver::class);
    }

    /** Settings that were removed fail the boot while still set, rather than look honoured. */
    public static function refuseRemovedSettings(): void
    {
        $legacy = config('openpne.images.legacy_driver');

        if ($legacy !== null && $legacy !== '') {
            throw new InvalidArgumentException(
                "OPENPNE_IMAGE_DRIVER [{$legacy}] is no longer read: unset it and use OPENPNE_IMAGE_PROCESSOR=gd (imagick support was removed). With a cached config, delete bootstrap/cache/config.php as well.",
            );
        }

        $strip = config('openpne.images.legacy_strip_metadata');

        if ($strip !== null && $strip !== '') {
            throw new InvalidArgumentException(
                'OPENPNE_STRIP_IMAGE_METADATA is no longer read: every inline picture is a re-encode without metadata, so unset it (docs/internals/security.md). With a cached config, delete bootstrap/cache/config.php as well.',
            );
        }
    }
}
