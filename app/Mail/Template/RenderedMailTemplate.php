<?php

declare(strict_types=1);

namespace App\Mail\Template;

final class RenderedMailTemplate
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
    ) {}
}
