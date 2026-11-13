<?php

namespace App\Files;

use App\Models\File;

/**
 * The picture shape every Modern serializer ships, built here so an `_a` URL has one producer
 * (docs/internals/images.md, "Which placements animate").
 */
final class ImageLadder
{
    private const THUMBNAIL = 120;

    private const FIT_BOXES = [320, 640, 1200];

    private const CROPS = [
        'tall' => [[300, 400], [600, 800]],
        'wide' => [[300, 200], [600, 400]],
    ];

    /**
     * @return array{thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null, animatedSources: list<array{url: string, box: int}>}
     */
    public static function of(?File $file): array
    {
        $format = $file === null ? null : self::variantFormat();

        return [
            // The 120px square stays in the file's own format: Classic and the digests read this key too.
            'thumbnailUrl' => $file?->thumbnailUrl(self::THUMBNAIL, self::THUMBNAIL, square: true) ?? '',
            'fitSources' => $file ? array_map(
                fn (int $box): array => ['url' => $file->thumbnailUrl($box, $box, outputFormat: $format), 'box' => $box],
                self::FIT_BOXES,
            ) : [],
            'cropSources' => $file ? array_map(
                fn (array $cells): array => array_map(
                    fn (array $cell): array => ['url' => $file->thumbnailUrl($cell[0], $cell[1], square: true, outputFormat: $format), 'width' => $cell[0]],
                    $cells,
                ),
                self::CROPS,
            ) : [],
            'width' => $file?->width,
            'height' => $file?->height,
            'animatedSources' => $file ? self::animatedSources($file, $format) : [],
        ];
    }

    /**
     * Every rung of() offers but the animated ones, as what ImageCache draws; none for a file that is not a raster.
     *
     * @return list<array{0: ImageTransform, 1: string}>
     */
    public static function stills(File $file): array
    {
        $own = $file->imageFormat();

        if ($own === null) {
            return [];
        }

        $format = self::variantFormat() ?? $own;
        $stills = [[new ImageTransform(self::THUMBNAIL, self::THUMBNAIL, true), $own]];

        foreach (self::FIT_BOXES as $box) {
            $stills[] = [new ImageTransform($box, $box, false), $format];
        }

        foreach (self::CROPS as $cells) {
            foreach ($cells as [$width, $height]) {
                $stills[] = [new ImageTransform($width, $height, true), $format];
            }
        }

        return $stills;
    }

    /** The same intake the image route's capability gate reads, so no rung is offered that the route would refuse. */
    public static function variantFormat(): ?string
    {
        return app(ImageProcessor::class)->intake()->writesWebp() ? 'webp' : null;
    }

    /**
     * Empty unless the processor keeps frames as well as the file being recorded as animating: under
     * GD, a true recorded by an earlier imgproxy run would otherwise offer a still at an `_a` URL.
     *
     * @return list<array{url: string, box: int}>
     */
    private static function animatedSources(File $file, ?string $format): array
    {
        if ($file->animated !== true || ! app(ImageProcessor::class)->preservesAnimation()) {
            return [];
        }

        $sources = [];
        foreach (config('openpne.images.animated_sizes') as $size) {
            [$width, $height] = array_map(intval(...), explode('x', $size));
            $sources[] = ['url' => $file->thumbnailUrl($width, $height, animated: true, outputFormat: $format), 'box' => $width];
        }

        return $sources;
    }
}
