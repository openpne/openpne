<?php

declare(strict_types=1);

namespace App\Support;

final readonly class ConvertedOperatorHtml
{
    /**
     * @param  array<string, int>  $droppedElements  element name => count, for elements removed with their content
     * @param  array<string, int>  $droppedAttributes  attribute name => count, for attributes Markdown has no place for
     */
    public function __construct(
        public string $markdown,
        public array $droppedElements = [],
        public array $droppedAttributes = [],
    ) {}

    public function isLossy(): bool
    {
        return $this->droppedElements !== [] || $this->droppedAttributes !== [];
    }
}
