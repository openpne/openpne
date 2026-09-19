<?php

namespace App\Features\Reactions\Exceptions;

use DomainException;

/** The content was gone by the time the write held its locks. */
class ReactionRefused extends DomainException {}
