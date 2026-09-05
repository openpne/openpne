<?php

declare(strict_types=1);

namespace App\Mail\Template;

/**
 * Source strings are untranslated — MailTemplate applies __() in its accessors — so building a definition
 * needs no booted translator.
 */
final readonly class MailTemplateDefinition
{
    /**
     * @param  string  $caption  the untranslated source string
     * @param  array<string, array{help: string, sample: mixed}>  $variables  keyed by the dot path the
     *                                                                        admin writes in `{{ … }}`, undotted before rendering. A key and its own prefix (`member` and
     *                                                                        `member.name`) must not both appear, and a name holding a literal dot is unrepresentable.
     */
    public function __construct(
        public ?string $op3SourceName,
        public bool $isConfigurable,
        public string $caption,
        public array $variables,
    ) {}
}
