<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\Runner\SourcePreflight;
use PHPUnit\Framework\TestCase;

class UnknownNameFamiliesTest extends TestCase
{
    public function test_numbered_names_fold_and_everything_else_stays_a_name(): void
    {
        $groups = SourcePreflight::families(['b' => 1, 'c_9' => 1, 'foo_1' => 1, 'a_1' => 2, 'foo' => 1, 'a_2' => 3, 'a_1x' => 4]);

        $this->assertSame(['a_*', 'a_1x', 'b', 'c_9', 'foo', 'foo_1'], array_keys($groups));
        $this->assertSame(['names' => ['a_1', 'a_2'], 'rows' => 5], $groups['a_*']);
        $this->assertSame(['names' => ['foo_1'], 'rows' => 1], $groups['foo_1'], 'an unnumbered sibling never joins');
        $this->assertSame(['names' => ['c_9'], 'rows' => 1], $groups['c_9'], 'a family of one is a name');
    }

    public function test_families_sort_by_rows_then_name(): void
    {
        $groups = SourcePreflight::families(['z_1' => 1, 'z_2' => 1, 'm' => 2, 'k_3' => 1, 'k_4' => 1]);

        $this->assertSame(['k_*', 'm', 'z_*'], array_keys($groups));
    }
}
