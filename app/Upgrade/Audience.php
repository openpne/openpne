<?php

namespace App\Upgrade;

use App\Support\Visibility;

/**
 * A target column that holds an audience, with the source values its mapping reads and, for each, the
 * values that mapping was written for (docs/internals/upgrade.md, "Audience distribution").
 */
final class Audience
{
    /**
     * @param  array<string, array{string, list<int|string|null>}>  $sources  label => [SQL over the step's FROM row, expected values]
     * @param  list<string>  $partitionBy  target columns the distribution is compared within
     */
    public function __construct(
        public readonly string $column,
        public readonly array $sources,
        public readonly array $partitionBy = [],
    ) {}

    /** @return list<int> */
    public static function publicFlagScale(): array
    {
        return array_map(static fn (Visibility $v): int => $v->openPne3PublicFlag(), Visibility::cases());
    }
}
