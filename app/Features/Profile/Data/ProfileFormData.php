<?php

namespace App\Features\Profile\Data;

/**
 * Validated profile-edit submission: the member's nickname plus, keyed by profile id, the
 * submitted value(s) and the chosen per-value visibility. A checkbox value is a list of option
 * ids; everything else is a scalar string. A null/absent visibility means "follow the field
 * default" (also the case for fields whose flag is not member-editable).
 */
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
