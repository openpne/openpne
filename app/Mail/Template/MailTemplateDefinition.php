<?php

declare(strict_types=1);

namespace App\Mail\Template;

/**
 * The full per-template registry entry, colocated so adding or changing a template touches one place
 * instead of one arm in each of MailTemplate's accessor methods. Holds untranslated source strings
 * (caption, variable help) — MailTemplate applies __() at the boundary, so building a definition needs
 * no booted translator.
 *
 * `variables` is the single source of truth for the template's variable set: each entry carries the
 * admin help text and a representative sample. variableHelp() and representativeContext() are both
 * derived from it, so the documented variables and the syntax-check fixtures cannot drift apart.
 */
final readonly class MailTemplateDefinition
{
    /**
     * @param  string  $caption  untranslated admin caption (source key for __())
     * @param  array<string, array{help: string, sample: mixed}>  $variables  keyed by the bare token the
     *                                                                        admin writes in `{{ … }}`. Keys are dot paths: `member.name` documents `{{ member.name }}` and
     *                                                                        representativeContext() undots it to `['member' => ['name' => …]]`. A key and its own prefix
     *                                                                        (e.g. `member` and `member.name`) must not both appear — the undot would clobber one. This and
     *                                                                        literal-dot names being unrepresentable are the dotted-path contract, pinned by MailTemplateTest.
     */
    public function __construct(
        public ?string $op3SourceName,
        public bool $isConfigurable,
        public string $caption,
        public array $variables,
    ) {}
}
