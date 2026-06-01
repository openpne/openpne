<?php

namespace Tests\Feature\Upgrade;

use Tests\TestCase;

class UpgradeMatrixCommandTest extends TestCase
{
    public function test_renders_targets_filters_and_gaps(): void
    {
        $this->artisan('openpne:upgrade-matrix')
            ->assertSuccessful()
            ->expectsOutputToContain('member_relationship')
            // The filter is what splits one source table across targets — it must be visible.
            ->expectsOutputToContain('Filter: `is_friend = 1`')
            ->expectsOutputToContain('Filter: `is_friend_pre = 1`')
            ->expectsOutputToContain('Filter: `is_access_block = 1`')
            // Member's deferred credentials must stay visible in the matrix.
            ->expectsOutputToContain('Pending targets:')
            ->expectsOutputToContain('`password`')
            ->expectsOutputToContain('Accepted gaps:')
            // Source tables with a successor but no step yet must stay visible too.
            ->expectsOutputToContain('Deferred source tables')
            ->expectsOutputToContain('`file_bin`');
    }
}
