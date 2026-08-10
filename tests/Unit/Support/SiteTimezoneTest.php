<?php

namespace Tests\Unit\Support;

use App\Support\SiteTimezone;
use InvalidArgumentException;
use Tests\TestCase;

class SiteTimezoneTest extends TestCase
{
    public function test_accepts_the_zones_a_site_would_be_configured_with(): void
    {
        foreach (['UTC', 'Asia/Tokyo', 'America/New_York', 'Etc/UTC'] as $timezone) {
            SiteTimezone::assertUsable($timezone);
        }

        $this->expectNotToPerformAssertions();
    }

    /**
     * A typo and a bare offset both have to fail here rather than at LoadConfiguration, which would
     * only warn and silently keep the previous zone.
     */
    public function test_rejects_anything_php_would_not_resolve_as_an_iana_name(): void
    {
        foreach (['Asia/Tokyoo', '+09:00', 'JST', ''] as $timezone) {
            try {
                SiteTimezone::assertUsable($timezone);
                $this->fail("expected [{$timezone}] to be rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('APP_TIMEZONE', $e->getMessage());
            }
        }
    }
}
