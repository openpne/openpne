<?php

namespace App\Features\Timeline\Exceptions;

use DomainException;

/**
 * Someone tried to write into a community's timeline without belonging to it. Thrown by the write
 * actions rather than checked by a controller: an everyone-readable community is reachable by any
 * member, so read access is not the answer to whether they may post.
 */
class NotGroupMember extends DomainException {}
