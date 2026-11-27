<?php

namespace App\Features\Auth;

use RuntimeException;

/** Thrown inside the transaction that would create the member, so nothing was written. */
class RegistrationLinkUnusable extends RuntimeException
{
    /** @param  bool  $refused  the registration mode refuses the link, rather than the link being replaced or past its lifetime */
    public function __construct(public readonly bool $refused)
    {
        parent::__construct($refused ? 'The registration mode refuses this link.' : 'The registration link was replaced or expired.');
    }
}
