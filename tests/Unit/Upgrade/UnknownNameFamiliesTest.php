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

    public function test_a_purely_numeric_name_sorts_as_a_string_beside_the_others(): void
    {
        $groups = SourcePreflight::families(['12' => 1, 'a' => 1, '3' => 1]);

        $this->assertSame(['12', '3', 'a'], array_map('strval', array_keys($groups)));
    }

    public function test_the_family_sample_is_in_natural_order(): void
    {
        $groups = SourcePreflight::families(['x_10' => 1, 'x_2' => 1, 'x_1' => 1]);

        $this->assertSame(['x_1', 'x_2', 'x_10'], $groups['x_*']['names']);
    }
}
