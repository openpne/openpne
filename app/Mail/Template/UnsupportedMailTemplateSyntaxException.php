<?php

declare(strict_types=1);

namespace App\Mail\Template;

use RuntimeException;

/**
 * A mail template used a construct outside the supported OpenPNE 3 dialect subset — e.g. `{% for %}`,
 * a `|filter`, `include_component`, or an `app_url_for` route with no OpenPNE 4 mapping. Surfaced
 * (not silently dropped or sent raw) so the import preflight can list offending templates.
 */
class UnsupportedMailTemplateSyntaxException extends RuntimeException {}
