<?php

declare(strict_types=1);

namespace App\Support;

final readonly class OperatorContentItem
{
    /**
     * @param  string  $location  where the operator HTML is stored, e.g. `sns_settings:footer_after`
     * @param  bool  $selected  false when the Markdown was already there and is left alone
     * @param  bool  $written  false on a dry run even when selected
     */
    public function __construct(
        public string $location,
        public ConvertedOperatorHtml $converted,
        public bool $selected,
        public bool $written,
    ) {}
}
