<?php

namespace App\Features\Timeline;

/** Who wrote a timeline post: the member through a compose form, or the site announcing something they created. */
enum TimelinePostOrigin
{
    case Member;

    case Auto;
}
