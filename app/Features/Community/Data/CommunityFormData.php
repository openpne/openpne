<?php

namespace App\Features\Community\Data;

use App\Features\Community\JoinPolicy;

final readonly class CommunityFormData
{
    public function __construct(
        public string $name,
        public ?string $description,
        public JoinPolicy $registerPolicy,
        public ?int $categoryId,
    ) {}
}
