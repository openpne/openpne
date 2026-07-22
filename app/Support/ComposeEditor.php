<?php

namespace App\Support;

/**
 * How the Modern compose forms (diary / topic / event) open the body: Rich is the WYSIWYG editor,
 * Markdown the raw textarea over Markdown source, Plain the raw textarea over unformatted text. A
 * member pins the choice via PreferenceKey::ComposeEditor; absent a choice the registry default
 * (Rich) applies. The values double as the Modern input-method menu's selection — see
 * resources/js/components/compose/editor-mode.ts.
 */
enum ComposeEditor: string
{
    case Rich = 'rich';

    case Markdown = 'markdown';

    case Plain = 'plain';
}
