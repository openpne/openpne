<?php

namespace App\Features\Timeline\Data;

use App\Support\Visibility;

final readonly class TimelinePostFormData
{
    public function __construct(
        public string $body,
        public Visibility $visibility,
    ) {}
}
