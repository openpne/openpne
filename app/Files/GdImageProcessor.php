<?php

namespace App\Files;

use GdImage;
use Intervention\Image\Drivers\Gd\Cloner;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * The header is measured before anything is decoded, since an out-of-memory fatal cannot be caught; the
 * bundled libgd allocates inside memory_limit and an external one outside it (docs/internals/security.md,
 * "Decoding an upload").
 */
final class GdImageProcessor implements ImageProcessor
{
    /** EXIF orientation => [clockwise degrees, then a horizontal flip], intervention/image's OrientModifier table. */
    private const ORIENTATIONS = [2 => [0, true], 3 => [180, false], 4 => [180, true], 5 => [90, true], 6 => [90, false], 7 => [270, true], 8 => [270, false]];

    /** decodeAnimation off, so a decode allocates one frame whatever the source holds; an animated WebP libgd does not read at all. */
    private readonly ImageManager $manager;

    public function __construct()
    {
        // Orientation is applied here instead, as intervention/image's keeps the source, the rotation and a copy of it at once.
        $this->manager = new ImageManager(GdDriver::class, decodeAnimation: false, autoOrientation: false);
    }

    public function preservesAnimation(): bool
    {
        return false;
    }

    public function intake(): ImageIntake
    {
        return ImageIntake::gd();
    }

    public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
    {
        ImageSourceLimit::preflight($bytes, $this->intake());

        try {
            $image = $this->manager->decode($bytes);
        } catch (Throwable $e) {
            throw new ImageProcessingException('The image could not be decoded: '.$e->getMessage(), 0, $e);
        }

        try {
            $this->orient($image);
        } catch (ImageProcessingException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ImageProcessingException('The image could not be turned upright: '.$e->getMessage(), 0, $e);
        }

        if ($image->isAnimated()) {
            $image->removeAnimation();
        }

        if ($spec->cover) {
            $image->cover((int) $spec->width, (int) $spec->height);
        } elseif (! $spec->isCanonical()) {
            $image->scaleDown((int) $spec->width, (int) $spec->height);
        }

        if ($spec->background !== null) {
            $image->fillTransparentAreas($spec->background);
        }

        $quality = (int) config('openpne.images.quality');

        $media = ImageSpec::mimeFor($spec->format);

        // The decoded content's own type, as an OpenPNE 3 row's stored type may name another.
        if ($spec->isCanonical() && in_array($spec->format, ['jpg', 'png', 'gif'], true) && (string) $image->origin()->mediaType() === $media) {
            // The JPEG encoder's own range, which imagejpeg() would answer with a ValueError or, for -1, its default.
            if ($spec->format === 'jpg' && ($quality < 0 || $quality > 100)) {
                throw new ImageProcessingException("The quality {$quality} is outside 0 to 100.");
            }

            try {
                $native = $image->core()->native();
                $key = imagecolortransparent($native);

                // An RGB PNG's opaque tRNS key becomes alpha only through the encoder's copy, made here before the source is let go so the two never meet libgd's writer.
                if ($spec->format === 'png' && $key !== -1 && ($key >> 24 & 0x7F) === 0) {
                    $copy = Cloner::clone($native);
                    unset($native);
                    $image->core()->setNative($copy);
                }

                $written = $this->writeNative($image->core()->native(), $spec->format, $quality, $bytes);
            } catch (ImageProcessingException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new ImageProcessingException('The image could not be encoded: '.$e->getMessage(), 0, $e);
            }

            return new ProcessedImage($written, $media, $image->width(), $image->height(), AnimationProbe::mayAnimate($media) ? null : false);
        }

        try {
            $encoded = $image->encodeUsingFileExtension($spec->format, quality: $quality);
        } catch (Throwable $e) {
            throw new ImageProcessingException('The image could not be encoded: '.$e->getMessage(), 0, $e);
        }

        // Keeping one frame of anything it reads, GD cannot tell whether a GIF or WebP animates and leaves it unjudged (docs/internals/images.md, "files.width / files.height").
        return new ProcessedImage($encoded->toString(), $encoded->mediaType(), $image->width(), $image->height(), AnimationProbe::mayAnimate($encoded->mediaType()) ? null : false);
    }

    /**
     * Writes the decoded image itself where intervention/image's encoder would write a full copy of it, with
     * the flags that copy carries, so the bytes are the encoder's for a JPEG and an opaque PNG or GIF.
     */
    private function writeNative(GdImage $gd, string $format, int $quality, string $source): string
    {
        imageinterlace($gd, false);

        if ($format !== 'jpg') {
            imagealphablending($gd, true);
            imagesavealpha($gd, true);
        }

        // A transparent pixel decodes to this value, which a white key, the encoder's copy's, would leave opaque on the decoded image itself.
        if ($format === 'gif') {
            imagecolortransparent($gd, self::gifHasTransparency($source) ? 0x7F000000 : imagecolorallocatealpha($gd, 255, 255, 255, 127));
        }

        $stream = fopen('php://temp', 'r+');

        try {
            $written = match ($format) {
                'jpg' => imagejpeg($gd, $stream, $quality),
                'png' => imagepng($gd, $stream, -1),
                'gif' => imagegif($gd, $stream),
            };

            if (! $written) {
                throw new ImageProcessingException("The image could not be encoded as {$format}.");
            }

            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /** Whether the Graphic Control Extension of the first image, the one frame GD decodes, sets the transparency flag. */
    private static function gifHasTransparency(string $bytes): bool
    {
        $packed = ord($bytes[10] ?? "\0");
        $at = 13 + (($packed & 0x80) !== 0 ? 3 << (($packed & 0x07) + 1) : 0);
        $length = strlen($bytes);

        // libgd's reader skips any other byte until an extension or the image descriptor.
        while ($at < $length && $bytes[$at] !== "\x2C" && $bytes[$at] !== "\x3B") {
            if ($bytes[$at] !== "\x21") {
                $at++;

                continue;
            }

            if (($bytes[$at + 1] ?? '') === "\xF9" && (ord($bytes[$at + 3] ?? "\0") & 0x01) === 1) {
                return true;
            }

            // The extension's data sub-blocks, each led by its length, up to the zero-length terminator.
            $at += 2;
            while ($at < $length && ($size = ord($bytes[$at])) !== 0) {
                $at += $size + 1;
            }
            $at++;
        }

        return false;
    }

    /** Only a JPEG carries the EXIF intervention/image reads, and a JPEG is truecolor without a transparent colour. */
    private function orient(ImageInterface $image): void
    {
        $orientation = $image->exif('IFD0.Orientation');
        [$degrees, $flip] = self::ORIENTATIONS[is_numeric($orientation) ? (int) $orientation : 0] ?? [0, false];
        $core = $image->core();

        if ($degrees !== 0) {
            $source = $core->native();
            $resolution = imageresolution($source);
            $rotated = imagerotate($source, -$degrees, (int) imagecolorallocatealpha($source, 255, 255, 255, 0));

            if ($rotated === false) {
                throw new ImageProcessingException('The image could not be rotated upright.');
            }

            unset($source);
            if (is_array($resolution)) {
                imageresolution($rotated, $resolution[0], $resolution[1]);
            }
            imagealphablending($rotated, true);
            imagesavealpha($rotated, true);
            $core->setNative($rotated);
        }

        if ($flip) {
            imageflip($core->native(), IMG_FLIP_HORIZONTAL);
        }
    }
}
