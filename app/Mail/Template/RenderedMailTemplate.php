<?php

declare(strict_types=1);

namespace App\Mail\Template;

/** A rendered template for one recipient: a single-line subject and the rendered (multi-line) body. */
final class RenderedMailTemplate
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
    ) {}
}
