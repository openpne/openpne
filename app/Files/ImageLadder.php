<?php

namespace App\Files;

use App\Models\File;

/**
 * The picture shape every Modern serializer ships, built here so an `_a` URL has one producer
 * (docs/internals/images.md, "Which placements animate").
 */
final class ImageLadder
{
    /**
     * @return array{thumbnailUrl: string, fitSources: list<array{url: string, box: int}>, cropSources: array{tall?: list<array{url: string, width: int}>, wide?: list<array{url: string, width: int}>}, width: int|null, height: int|null, animatedSources: list<array{url: string, box: int}>}
     */
    public static function of(?File $file): array
    {
        $format = $file === null ? null : self::variantFormat();

        return [
            // The 120px square stays in the file's own format: Classic and the digests read this key too.
            'thumbnailUrl' => $file?->thumbnailUrl(120, 120, square: true) ?? '',
            'fitSources' => $file ? [
                ['url' => $file->thumbnailUrl(320, 320, outputFormat: $format), 'box' => 320],
                ['url' => $file->thumbnailUrl(640, 640, outputFormat: $format), 'box' => 640],
                ['url' => $file->thumbnailUrl(1200, 1200, outputFormat: $format), 'box' => 1200],
            ] : [],
            'cropSources' => $file ? [
                'tall' => [
                    ['url' => $file->thumbnailUrl(300, 400, square: true, outputFormat: $format), 'width' => 300],
                    ['url' => $file->thumbnailUrl(600, 800, square: true, outputFormat: $format), 'width' => 600],
                ],
                'wide' => [
                    ['url' => $file->thumbnailUrl(300, 200, square: true, outputFormat: $format), 'width' => 300],
                    ['url' => $file->thumbnailUrl(600, 400, square: true, outputFormat: $format), 'width' => 600],
                ],
            ] : [],
            'width' => $file?->width,
            'height' => $file?->height,
            'animatedSources' => $file ? self::animatedSources($file, $format) : [],
        ];
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
