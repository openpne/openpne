<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Artisan;
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
        Artisan::call('down');

        try {
            $response = $this->get('/');
        } finally {
            Artisan::call('up');
        }

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
