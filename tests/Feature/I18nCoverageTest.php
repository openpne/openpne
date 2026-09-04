<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Runs `i18n:check` inside the normal suite, so a translation literal pointing at a key absent from
 * lang/{ja,en}.json (outside the baseline) is blocked by the same gate as any other failure.
 */
class I18nCoverageTest extends TestCase
{
    public function test_no_new_translation_gaps_outside_baseline(): void
    {
        $exitCode = Artisan::call('i18n:check');

        $this->assertSame(
            0,
            $exitCode,
            'lang/{ja,en}.json has missing keys or invalid key order. Add the missing '
            .'entries (or run `php artisan i18n:check --update-baseline` if the gap is '
            .'pre-existing), run `php artisan i18n:check --sort` if the order is off, '
            ."then re-run `php artisan i18n:check` locally.\n\n"
            .Artisan::output()
        );
    }
}
