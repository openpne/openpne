<?php

namespace App\Support;

/** The values are also the Modern input-method menu's ids (resources/js/components/compose/editor-mode.ts). */
enum ComposeEditor: string
{
    case Rich = 'rich';

    case Markdown = 'markdown';

    case Plain = 'plain';
}
