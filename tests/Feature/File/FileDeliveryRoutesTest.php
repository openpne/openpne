<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Support\FileDeliveryRoutes;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** The list is spelled by name, so a renamed or removed route must fail here rather than fall silently out of both middleware. */
class FileDeliveryRoutesTest extends TestCase
{
    public function test_every_listed_name_is_a_registered_get_route(): void
    {
        foreach (FileDeliveryRoutes::NAMES as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertContains('GET', $route->methods(), $name);
        }
    }
}
