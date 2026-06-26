<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Wrapper around `php artisan i18n:check`. Fails CI when any `t()` / `__()` /
 * `@lang()` literal points at a key absent from lang/{ja,en}.json (modulo
 * baseline grandfathering). Runs as part of the normal test suite so missing
 * translations are blocked by the same gate as other failures.
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
