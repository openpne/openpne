<?php

namespace App\Features\GroupTopic\Data;

use App\Support\BodyFormat;

final readonly class GroupTopicFormData
{
    public function __construct(
        public string $name,
        public string $body,
        // null when the request omitted format: create defaults to Plain, update preserves the current.
        public ?BodyFormat $format = null,
    ) {}
}
