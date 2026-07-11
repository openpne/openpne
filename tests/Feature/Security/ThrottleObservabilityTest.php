<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

/**
 * A rate-limited request logs a single `throttle.hit` to the security channel and — thanks to the
 * report hook's ->stop() — nothing to the default channel (docs/internals/logging.md).
 */
class ThrottleObservabilityTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    public function test_a_429_logs_one_security_event_and_nothing_to_the_default_channel(): void
    {
        $this->captureSecurityLog();
        $this->captureDefaultLog();

        // Per-member cap of 1 (per-IP loose so it never trips first), same lever the write-throttle
        // tests use: the second post exceeds it.
        config(['openpne.throttle.posting' => 1, 'openpne.throttle.posting_ip' => 1000]);
        $member = Member::factory()->create();

        $this->flushSession();
        $this->actingAs($member)->post('/diary/create');
        $this->flushSession();
        $this->actingAs($member)->post('/diary/create')->assertStatus(429);

        $this->assertCount(1, $this->securityRecords('throttle.hit'));
        $this->assertEmpty($this->defaultLogHandler->getRecords(), 'a 429 must not reach the default channel');
    }
}
