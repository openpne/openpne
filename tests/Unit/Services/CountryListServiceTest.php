<?php

namespace Tests\Unit\Services;

use App\Services\CountryListService;
use Tests\TestCase;

class CountryListServiceTest extends TestCase
{
    public function test_options_are_keyed_by_iso_code(): void
    {
        $options = (new CountryListService)->getOptions('en');

        $this->assertArrayHasKey('JP', $options);
        $this->assertSame('Japan', $options['JP']);
    }

    public function test_country_name_is_localised(): void
    {
        $service = new CountryListService;

        $this->assertSame('Japan', $service->getName('JP', 'en'));
        $this->assertSame('日本', $service->getName('JP', 'ja'));
        $this->assertSame('日本', $service->getName('JP', 'ja_JP')); // accepts the translation lang too
    }

    public function test_unknown_code_falls_back_to_the_code(): void
    {
        $this->assertSame('ZZ', (new CountryListService)->getName('ZZ', 'en'));
    }
}
