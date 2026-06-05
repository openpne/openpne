<?php

namespace Tests\Feature\Community;

use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;

trait AssertsCommunityFailure
{
    private function assertFailsWith(CommunityActionFailure $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected CommunityActionException ({$reason->value})");
        } catch (CommunityActionException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
