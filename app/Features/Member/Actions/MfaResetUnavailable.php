<?php

namespace App\Features\Member\Actions;

use RuntimeException;

/**
 * A reset link cannot be issued for this member right now: the locked re-check found no live factor
 * or no registered address (typically a disable that raced the admin's click). Deliberately its own
 * type so callers can treat exactly this precondition failure as the benign race — any other failure
 * inside RequestMfaReset (logging, mail dispatch) must keep bubbling as the fault it is.
 */
class MfaResetUnavailable extends RuntimeException {}
