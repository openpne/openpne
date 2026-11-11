<?php

declare(strict_types=1);

namespace App\Support;

final readonly class OperatorContentItem
{
    /**
     * @param  string  $location  where the operator HTML is stored, e.g. `sns_settings:footer_after`
     * @param  bool  $written  false when the Markdown was already there and left alone, or on a dry run
     */
    public function __construct(
        public string $location,
        public ConvertedOperatorHtml $converted,
        public bool $written,
    ) {}
}
