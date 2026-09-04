<?php

namespace App\Support;

/**
 * Op3 marks a body upgraded from OpenPNE 3 with its <op:*> decoration; Plain is the default for
 * bodies written here.
 */
enum BodyFormat: string
{
    case Plain = 'plain';

    case Op3 = 'op3';

    case Markdown = 'markdown';
}
