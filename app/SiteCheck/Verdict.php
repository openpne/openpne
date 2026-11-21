<?php

declare(strict_types=1);

namespace App\SiteCheck;

enum Verdict: string
{
    case Ok = 'ok';
    /** Something the site lacks; the command exits 1. */
    case Fail = 'fail';
    /** Worth acting on, though the site works without it. */
    case Warn = 'warn';
    case Unknown = 'unknown';
}
