<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Http\Middleware\StartSession;
use App\Support\FileDeliveryRoutes;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** The lists are spelled by name, so a renamed or removed route must fail here rather than fall silently out of the middleware. */
class FileDeliveryRoutesTest extends TestCase
{
    public function test_every_listed_name_is_a_registered_get_route(): void
    {
        foreach ([...FileDeliveryRoutes::NAMES, ...StartSession::ASSET_ROUTES] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertContains('GET', $route->methods(), $name);
        }
    }
}
