<?php

namespace App\Compat;

/**
 * One surface element of an OpenPNE 3 screen (a rendered field, link, widget, or behaviour),
 * with its Classic-adapter porting status. The third parity axis: route parity says the URL
 * resolves and upgrade matrix says the data moves; this says the screen's content is present.
 *
 * `op3Source` names where the element comes from in the OpenPNE 3 template/helper, so the
 * inventory is auditable against the real template rather than asserted from memory.
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
