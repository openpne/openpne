<?php

namespace App\Features\CommunityTopic\Data;

final readonly class CommunityTopicFormData
{
    public function __construct(
        public string $name,
        public string $body,
    ) {}
}
