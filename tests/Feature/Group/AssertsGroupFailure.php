<?php

namespace Tests\Feature\Group;

use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;

trait AssertsGroupFailure
{
    private function assertFailsWith(GroupActionFailure $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected GroupActionException ({$reason->value})");
        } catch (GroupActionException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
