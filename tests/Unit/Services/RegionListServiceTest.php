<?php

namespace Tests\Unit\Services;

use App\Services\RegionListService;
use Tests\TestCase;

class RegionListServiceTest extends TestCase
{
    public function test_flatten_options_lists_a_country_regions(): void
    {
        $regions = (new RegionListService)->flattenOptions('JP');

        $this->assertContains('Tokyo', $regions);
        $this->assertContains('Hokkaido', $regions);
    }

    public function test_flat_options_are_localised_and_keyed_by_the_source(): void
    {
        $options = (new RegionListService)->getOptions('JP', 'ja');

        $this->assertSame('東京都', $options['Tokyo']);
    }

    public function test_string_value_type_groups_by_country(): void
    {
        $options = (new RegionListService)->getOptions('string', 'en');

        $first = reset($options);
        $this->assertIsArray($first); // grouped: country name => [region => label]
    }

    public function test_label_translates_or_falls_back_to_the_source(): void
    {
        $service = new RegionListService;

        $this->assertSame('東京都', $service->label('Tokyo', 'ja'));
        $this->assertSame('Tokyo', $service->label('Tokyo', 'en'));
        // No ja translation exists for non-JP regions, so the source string is shown.
        $this->assertSame('California', $service->label('California', 'ja'));
    }
}
