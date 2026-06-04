<?php

namespace Tests\Unit\Support;

use App\Support\LocalizedDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class LocalizedDateTest extends TestCase
{
    public function test_japanese_locale_uses_the_kanji_datetime_pattern(): void
    {
        App::setLocale('ja');

        $this->assertSame(
            '2026年06月04日 13:44',
            LocalizedDate::dateTime(CarbonImmutable::create(2026, 6, 4, 13, 44)),
        );
    }

    public function test_other_locales_use_a_localized_datetime(): void
    {
        App::setLocale('en');

        $this->assertSame(
            'June 4, 2026 1:44 PM',
            LocalizedDate::dateTime(CarbonImmutable::create(2026, 6, 4, 13, 44)),
        );
    }
}
