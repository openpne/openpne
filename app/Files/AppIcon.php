<?php

namespace App\Files;

use App\Models\File;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;

/**
 * Home-screen icon bytes derived from the branding favicon: the web app manifest icons and the
 * apple-touch-icon, which a launcher draws far larger than a browser tab draws a favicon.
 *
 * Two rules keep a tab-sized favicon from becoming a bad app icon. A source too small to fill the
 * requested box keeps the shipped icon rather than being upscaled into a blurred one. Transparency
 * is flattened onto the white the manifest declares as its background_color, because iOS composites
 * an alpha channel against black.
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
        private readonly FileStorage $storage,
        private readonly ImageManager $manager,
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

        return $token === '' ? null : File::query()->where('name', $token)->first();
    }

    /**
     * PNG bytes of the app icon at $size, generating and caching on a miss. Cached under the
     * source's name token so FileObserver's purge takes these with it, and a replaced favicon —
     * a new token — can never read the old icon back.
     */
    public function bytes(File $source, int $size): string
    {
        $disk = $this->disk();
        $key = "{$source->name}/app-icon-{$size}.png";

        // Only a generated icon is ever cached, so a hit also settles that the source was big enough.
        if ($disk->exists($key)) {
            return (string) $disk->get($key);
        }

        $original = $this->original($source);

        // The image header is enough to rule the source out. The fallback is deliberately not
        // cached: the shipped icon is the app's own asset and must not be frozen into a
        // derived-bytes cache that outlives the upgrade that replaces it.
        $dimensions = @getimagesizefromstring($original);
        if ($dimensions === false || min($dimensions[0], $dimensions[1]) < $size) {
            return self::shippedBytes($size);
        }

        $bytes = $this->generate($original, $size);
        $disk->put($key, $bytes);

        return $bytes;
    }

    private function generate(string $original, int $size): string
    {
        return $this->manager->decode($original)
            ->cover($size, $size)
            ->fillTransparentAreas('ffffff')
            ->encode(new PngEncoder)
            ->toString();
    }

    private static function shippedBytes(int $size): string
    {
        return (string) file_get_contents(public_path(self::shippedAsset($size)));
    }

    private function original(File $file): string
    {
        $stream = $this->storage->readStream($file);
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return (string) $bytes;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('openpne.images.cache_disk'));
    }
}
