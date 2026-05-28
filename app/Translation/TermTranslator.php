<?php

declare(strict_types=1);

namespace App\Translation;

use App\Services\TermService;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Translation\Translator;

/**
 * Wraps the framework Translator so that `%name%` placeholders in resolved
 * messages are substituted with the administrator-configured term values.
 */
class TermTranslator extends Translator
{
    public function __construct(
        Loader $loader,
        string $locale,
        private readonly TermService $terms,
    ) {
        parent::__construct($loader, $locale);
    }

    public function get($key, array $replace = [], $locale = null, $fallback = true): string|array
    {
        $translation = parent::get($key, $replace, $locale, $fallback);

        if (is_string($translation)) {
            return $this->terms->replace($translation, $locale ?? $this->locale);
        }

        return $translation;
    }
}
