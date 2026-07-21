<?php

namespace App\Support;

/**
 * How a record's stored body text is rendered.
 *
 * Plain is the OpenPNE 4 default (op_url_cmd(nl2br(...)); see BodyText). Op3 carries the
 * OpenPNE 3 rich-text decoration (<op:*> tags) found in migrated diary bodies (see Op3Text).
 * Markdown is the opt-in authorable format (see MarkdownText). BodyRenderer maps each case
 * to its renderer.
 */
enum BodyFormat: string
{
    case Plain = 'plain';

    case Op3 = 'op3';

    case Markdown = 'markdown';
}
