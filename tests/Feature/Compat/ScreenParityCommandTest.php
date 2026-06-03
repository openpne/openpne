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
            ->expectsOutputToContain('comment thread (author, number, delete)')
            ->expectsOutputToContain('visibility label')
            // expectsOutputToContain matches each line to one expectation, so overlapping
            // substrings on a line starve each other: the Coverage line also carries the word
            // "partial" (a count), so it must be claimed before the bare-word `partial` check.
            ->expectsOutputToContain('Coverage:')
            // The status word must print: Symfony Console strips the warning sign from ⚠️, so a
            // bare icon would render Partial unreadable. Lock the word so that cannot regress.
            ->expectsOutputToContain('partial');
    }
}
