<?php

namespace Tests\Feature\Compat;

use Tests\TestCase;

class RouteParityCommandTest extends TestCase
{
    public function test_renders_mappings_and_gaps(): void
    {
        $this->artisan('openpne:route-parity')
            ->assertSuccessful()
            ->expectsOutputToContain('`diary.show`')
            ->expectsOutputToContain('`home` | `/`') // root renders as /, not //
            ->expectsOutputToContain('Not ported:')
            ->expectsOutputToContain('diary_search');
    }
}
