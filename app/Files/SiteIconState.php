<?php

declare(strict_types=1);

namespace App\Files;

enum SiteIconState
{
    case Uploaded;

    case Generated;

    case None;
}
