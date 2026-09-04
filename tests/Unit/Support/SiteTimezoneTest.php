<?php

namespace Tests\Unit\Support;

use App\Support\SiteTimezone;
use InvalidArgumentException;
use Tests\TestCase;

class SiteTimezoneTest extends TestCase
{
    public function test_accepts_the_zones_a_site_would_be_configured_with(): void
    {
        foreach (['UTC', 'Asia/Tokyo', 'America/New_York'] as $timezone) {
            SiteTimezone::assertUsable($timezone);
        }

        $this->expectNotToPerformAssertions();
    }

    public function test_rejects_anything_that_is_not_a_canonical_iana_name(): void
    {
        // `Etc/UTC`, `GMT` and `Japan` are real aliases the client would accept, rejected so the configured value is canonical.
        foreach (['Asia/Tokyoo', '+09:00', 'JST', '', 'Etc/UTC', 'GMT', 'Japan'] as $timezone) {
            $this->assertRejected($timezone);
        }
    }

    public function test_rejects_the_tzdata_filenames_the_backward_compatible_group_leaks(): void
    {
        // These four are in `ALL_WITH_BC` but are tzdata files, not zones: Intl throws on them, and
        // `leapseconds` makes date_default_timezone_get raise "Timezone database is corrupt".
        foreach (['Factory', 'leapseconds', 'localtime', 'tzdata.zi'] as $timezone) {
            $this->assertRejected($timezone);
        }
    }

    private function assertRejected(string $timezone): void
    {
        try {
            SiteTimezone::assertUsable($timezone);
            $this->fail("expected [{$timezone}] to be rejected");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('APP_TIMEZONE', $e->getMessage());
        }
    }
}
