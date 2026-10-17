<?php

namespace Tests\Feature\Http;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Foundation\CacheBasedMaintenanceMode;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ServerErrorPagesTest extends TestCase
{
    public function test_a_503_page_is_the_plain_branded_one(): void
    {
        config(['app.name' => 'Test SNS']);
        Route::get('/_probe/503', fn () => abort(503));

        $response = $this->get('/_probe/503');

        $response->assertStatus(503);
        $response->assertSee('Test SNS');
        $response->assertSee(__('Under maintenance'));
        $response->assertSee(__('Back to the top page'));
    }

    public function test_maintenance_mode_renders_the_plain_page(): void
    {
        config(['app.name' => 'Test SNS']);
        // Held in this process's array cache: the file driver's flag under storage/ would put every
        // parallel worker, and the local site, into maintenance for the test's duration.
        $mode = new CacheBasedMaintenanceMode($this->app->make(Factory::class), 'array', 'test-maintenance');
        $mode->activate(['time' => now()->getTimestamp(), 'retry' => 60]);
        $this->app->instance(MaintenanceModeManager::class, new class($this->app, $mode) extends MaintenanceModeManager
        {
            public function __construct($app, private readonly CacheBasedMaintenanceMode $mode)
            {
                parent::__construct($app);
            }

            public function driver($driver = null): CacheBasedMaintenanceMode
            {
                return $this->mode;
            }
        });

        $response = $this->get('/');

        $response->assertStatus(503);
        $response->assertSee('Test SNS');
        $response->assertSee(__('Under maintenance'));
    }

    public function test_a_500_page_is_the_plain_branded_one(): void
    {
        config(['app.name' => 'Test SNS']);
        Route::get('/_probe/500', fn () => abort(500));

        $response = $this->get('/_probe/500');

        $response->assertStatus(500);
        $response->assertSee('Test SNS');
        $response->assertSee(__('Something went wrong'));
    }
}
