<?php

namespace App\Features\Profile\Data;

/** `values` and `visibilities` are keyed by profile id; a null visibility follows the field default. */
final readonly class ProfileFormData
{
    /**
     * @param  array<int, string|list<string>>  $values
     * @param  array<int, int|null>  $visibilities
     */
    public function __construct(
        public string $name,
        public array $values,
        public array $visibilities,
    ) {}
}
