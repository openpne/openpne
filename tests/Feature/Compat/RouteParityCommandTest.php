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
            // A parity bound to an OpenPNE 3 module of another name (policy → default) still scopes
            // its rows from the inventory; claimed here because each output line satisfies only the
            // first expectation that matches it.
            ->expectsOutputToContain('| 🔗 | `customizing_css` | `/cache/css/customizing.:sf_format` | GET | `design.customizing_css` |')
            ->expectsOutputToContain('- 🔗 `global_search` — Not ported:')
            ->expectsOutputToContain('Not ported:')
            ->expectsOutputToContain('diary_search');
    }
}
