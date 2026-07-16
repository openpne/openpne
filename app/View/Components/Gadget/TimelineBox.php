<?php

namespace App\View\Components\Gadget;

use App\Models\TimelinePost;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * Shared base for the OpenPNE 3 timeline gadgets: holds the posts each concrete kind renders through
 * the Classic timeline's shared _post partial. Each kind injects its own query and picks the subject.
 */
abstract class TimelineBox extends Component
{
    /** @var Collection<int, TimelinePost> */
    public Collection $posts;

    /** @param array<string, mixed> $config */
    protected static function limit(array $config): int
    {
        return max(1, (int) ($config['limit'] ?? 20));
    }
}
