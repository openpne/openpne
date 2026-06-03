<?php

namespace App\Compat;

/**
 * Classic compatibility priority of a surface element, per docs/internals/classic-compatibility.md.
 * Read this off the doc, not invented here: One = must preserve (migration success, primary flows,
 * theme hooks), Two = should preserve (parts structure, classes, body ids), Three = may differ.
 */
enum CompatLevel: string
{
    case One = 'L1';
    case Two = 'L2';
    case Three = 'L3';
}
