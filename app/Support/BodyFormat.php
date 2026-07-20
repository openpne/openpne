<?php

namespace App\Support;

/**
 * How a record's stored body text is rendered.
 *
 * Plain is the OpenPNE 4 default (op_url_cmd(nl2br(...)); see BodyText). Op3 carries the
 * OpenPNE 3 rich-text decoration (<op:*> tags) found in migrated diary bodies (see Op3Text).
 * Markdown is declared but not yet rendered — its renderer lands in a follow-up PR, so
 * BodyRenderer falls back to the plain path for it.
 */
enum BodyFormat: string
{
    case Plain = 'plain';

    case Op3 = 'op3';

    case Markdown = 'markdown';
}
