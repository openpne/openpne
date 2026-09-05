<?php

namespace App\Features\Member\Actions;

use RuntimeException;

/**
 * Its own type so a caller can treat this precondition failure — no live factor, or no registered
 * address — as the benign race, while every other failure in {@see RequestMfaReset} keeps bubbling.
 */
class MfaResetUnavailable extends RuntimeException {}
