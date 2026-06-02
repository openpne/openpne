<?php

namespace App\Features\Diary\Data;

use App\Support\Visibility;

final readonly class DiaryFormData
{
    public function __construct(
        public string $title,
        public string $body,
        public Visibility $visibility,
    ) {}
}
