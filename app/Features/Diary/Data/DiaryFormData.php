<?php

namespace App\Features\Diary\Data;

use App\Support\BodyFormat;
use App\Support\Visibility;

final readonly class DiaryFormData
{
    public function __construct(
        public string $title,
        public string $body,
        public Visibility $visibility,
        // null when the request omitted format: create defaults to Plain, update preserves the current.
        public ?BodyFormat $format = null,
    ) {}
}
