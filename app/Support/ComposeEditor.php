<?php

namespace App\Support;

/**
 * Which body-authoring editor the Modern compose forms (diary / topic / event) open with: Rich is
 * the WYSIWYG editor, Markdown the plain textarea. A member pins the choice via
 * PreferenceKey::ComposeEditor; absent a choice the registry default (Rich) applies.
 */
enum ComposeEditor: string
{
    case Rich = 'rich';

    case Markdown = 'markdown';

    /** Human-readable label key, translated via __()/t() on either surface. */
    public function label(): string
    {
        return match ($this) {
            self::Rich => 'Rich text',
            self::Markdown => 'Markdown',
        };
    }
}
