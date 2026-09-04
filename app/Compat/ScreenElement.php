<?php

namespace App\Compat;

/**
 * `op3Source` names the OpenPNE 3 template or helper the element comes from, so the inventory
 * can be checked against the real template.
 */
final class ScreenElement
{
    public function __construct(
        public readonly string $name,
        public readonly CompatLevel $level,
        public readonly ScreenStatus $status,
        public readonly string $op3Source,
        public readonly ?string $note = null,
    ) {}
}
