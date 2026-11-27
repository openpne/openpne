<?php

declare(strict_types=1);

namespace App\Features\Auth;

use DomainException;

/** Rolls back a link written for an address a registration took after the first check. */
final class AddressRegisteredMeanwhile extends DomainException {}
