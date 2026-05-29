<?php

namespace App\Features\Diary\Data;

use App\Features\Diary\Visibility;

final readonly class DiaryFormData
{
    public function __construct(
        public string $title,
        public string $body,
        public Visibility $visibility,
    ) {}
}
