<?php

declare(strict_types=1);

namespace App\Gadgets;

/**
 * One configurable parameter of a gadget kind (OpenPNE 3 gadget.yml `config`). The same definition
 * drives both the admin edit form and render-time typed reads, so the two cannot drift.
 */
final class GadgetConfigField
{
    public const TEXT = 'text';

    public const INT = 'int';

    /**
     * @param  array<string, string>  $caption  locale (ja/en) => label
     * @param  array<int|string, string>  $choices  value => label, for select/radio
     */
    public function __construct(
        public readonly string $name,
        public readonly array $caption,
        public readonly string $formType,
        public readonly string $valueType = self::TEXT,
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly array $choices = [],
    ) {}

    /** The stored string coerced to this field's type, falling back to the default when unset. */
    public function value(?string $stored): mixed
    {
        $raw = $stored ?? $this->default;

        return $this->valueType === self::INT ? (int) $raw : (string) ($raw ?? '');
    }
}
