<?php

namespace App\Features\Timeline\Data;

use App\Support\Visibility;

final readonly class TimelinePostFormData
{
    /**
     * @param  list<array{member_id: int, offset: int, length: int}>  $mentions  the picker's selection, not yet resolved against the body (ResolveMentions)
     */
    public function __construct(
        public string $body,
        public Visibility $visibility,
        public array $mentions = [],
    ) {}
}
