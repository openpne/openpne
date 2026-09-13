<?php

declare(strict_types=1);

namespace App\Support;

/** Whether a picture that animates plays on its own in a Modern feed (docs/internals/images.md, "Which placements animate"). */
enum Autoplay: string
{
    case On = 'on';

    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::On => 'Play automatically',
            self::Off => 'Show a still',
        };
    }
}
