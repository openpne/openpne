<?php

namespace App\Files;

use App\Models\File;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Transparency is flattened onto the white the manifest declares as its `background_color`, because
 * iOS composites an alpha channel against black.
 */
class AppIcon
{
    /** The sizes the shells ask for: apple-touch-icon, then the two manifest icons. */
    public const SIZES = [180, 192, 512];

    /** Built-in icon per size: what an unbranded install links, and the too-small fallback. */
    private const SHIPPED = [
        180 => 'apple-touch-icon.png',
        192 => 'icon-192x192.png',
        512 => 'icon-512x512.png',
    ];

    public function __construct(
        private readonly ImageCache $cache,
        private readonly ImageProcessor $processor,
        private readonly SnsSettingService $settings,
    ) {}

    public static function shippedAsset(int $size): string
    {
        return self::SHIPPED[$size];
    }

    /** The uploaded favicon the icons derive from, or null on an unbranded install. */
    public function source(): ?File
    {
        $token = (string) $this->settings->get(SnsSettingKey::BrandFaviconFile);

        if ($token === '') {
            return null;
        }

        $file = File::query()->where('name', $token)->first();

        // The same boundary PublicFileController draws, for the same reason SaveBrandingSettings
        // re-checks before it deletes: a corrupted setting pointing at a member's private image must
        // not turn this route into a reader for it.
        if ($file === null || $file->explicit_visibility !== File::VISIBILITY_PUBLIC || ! Gate::allows('view', $file)) {
            return null;
        }

        return $file;
    }

    /**
     * PNG bytes of the app icon at $size, generating and caching on a miss. Cached under the
     * source's encoder directory so FileObserver's purge and a processor change both take these with
     * it, and a replaced favicon — a new token — can never read the old icon back.
     */
    public function bytes(File $source, int $size): string
    {
        $disk = $this->disk();
        $prefix = ImageTransform::encoderPrefix($source->name);
        $key = "{$prefix}/app-icon-{$size}.png";
        $tooSmall = "{$prefix}/app-icon-{$size}.unfit";
        $refused = "{$prefix}/app-icon-{$size}.refused";

        if ($disk->exists($key)) {
            return (string) $disk->get($key);
        }

        // Only the verdict (too small, or refused by the processor) is cached, never the shipped
        // bytes, so an upgrade that replaces the shipped asset takes effect.
        if ($disk->exists($tooSmall) || $disk->exists($refused)) {
            return self::shippedBytes($size);
        }

        try {
            $canonical = $this->cache->canonical($source);
        } catch (CanonicalUnavailableException) {
            $disk->put($refused, '');

            return self::shippedBytes($size);
        }

        // The canonical's header is enough to rule the source out.
        $dimensions = @getimagesizefromstring($canonical);
        if ($dimensions === false || min($dimensions[0], $dimensions[1]) < $size) {
            $disk->put($tooSmall, '');

            return self::shippedBytes($size);
        }

        try {
            $bytes = $this->generate($canonical, $source->type, $size);
        } catch (ImageProcessingException) {
            $disk->put($refused, '');

            return self::shippedBytes($size);
        }

        $this->cache->publish($key, $bytes);

        return $bytes;
    }

    private function generate(string $canonical, string $mime, int $size): string
    {
        return $this->processor
            ->process($canonical, $mime, ImageSpec::cover($size, $size, 'png')->withBackground('ffffff'))
            ->bytes;
    }

    private static function shippedBytes(int $size): string
    {
        return (string) file_get_contents(public_path(self::shippedAsset($size)));
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('openpne.images.cache_disk'));
    }
}
