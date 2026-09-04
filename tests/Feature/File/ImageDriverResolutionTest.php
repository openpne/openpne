<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use InvalidArgumentException;
use Tests\TestCase;

class ImageDriverResolutionTest extends TestCase
{
    private function driverFor(?string $configured): object
    {
        config(['openpne.images.driver' => $configured]);
        $this->app->forgetInstance(ImageManager::class);

        return $this->app->make(ImageManager::class)->driver;
    }

    public function test_gd_and_imagick_resolve_to_their_drivers(): void
    {
        $this->assertInstanceOf(GdDriver::class, $this->driverFor('gd'));
        $this->assertInstanceOf(ImagickDriver::class, $this->driverFor('imagick'));
    }

    /**
     * The removed vips option is the case that matters: it used to resolve, so an env that still
     * names it must fail rather than quietly become GD.
     */
    public function test_an_unsupported_driver_throws_rather_than_falling_back(): void
    {
        foreach (['vips', 'imagcik', '', null] as $configured) {
            try {
                $this->driverFor($configured);
                $this->fail('Expected '.var_export($configured, true).' to be rejected.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('openpne.images.driver', $e->getMessage());
            }
        }
    }
}
