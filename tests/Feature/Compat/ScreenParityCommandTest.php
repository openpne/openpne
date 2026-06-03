<?php

namespace Tests\Feature\Compat;

use Tests\TestCase;

class ScreenParityCommandTest extends TestCase
{
    public function test_renders_screens_elements_and_coverage(): void
    {
        $this->artisan('openpne:screen-parity')
            ->assertSuccessful()
            // A screen is headed by its OpenPNE 3 body id and the Laravel route it binds to.
            ->expectsOutputToContain('`page_diary_show` — `diary.show`')
            // A ported element and a missing one both surface, with the source column visible.
            ->expectsOutputToContain('comment list')
            ->expectsOutputToContain('visibility label')
            ->expectsOutputToContain('Coverage:');
    }
}
