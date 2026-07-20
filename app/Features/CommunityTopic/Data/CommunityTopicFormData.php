<?php

namespace App\Features\CommunityTopic\Data;

use App\Support\BodyFormat;

final readonly class CommunityTopicFormData
{
    public function __construct(
        public string $name,
        public string $body,
        // null when the request omitted format: create defaults to Plain, update preserves the current.
        public ?BodyFormat $format = null,
    ) {}
}
