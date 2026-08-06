<?php

declare(strict_types=1);

namespace App\Mail\Template;

use RuntimeException;
use Throwable;
use Twig\Error\Error as TwigError;
use Twig\Error\SyntaxError;
use Twig\Sandbox\SecurityError;

/**
 * A mail template did not render — e.g. `{% include %}`, a disallowed `|filter`, or an `app_url_for` route
 * with no OpenPNE 4 mapping. Surfaced (not silently dropped or sent raw) so the admin editor can reject it
 * and the import preflight can list it, grouped by `fault`.
 */
class UnsupportedMailTemplateSyntaxException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly MailTemplateFault $fault = MailTemplateFault::RenderFailure,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Classify a Twig failure by exception type. A runtime error wrapping one of ours is Twig re-throwing
     * what an `app_url_for` call raised, so the inner fault is the real one; anything else runtime is left
     * generic, since strict-variable misses and honest runtime faults are the same type (see MailTemplateFault).
     */
    public static function fromTwig(TwigError $e): self
    {
        $inner = $e->getPrevious();

        $fault = match (true) {
            $e instanceof SyntaxError => MailTemplateFault::ParseError,
            $e instanceof SecurityError => MailTemplateFault::SandboxViolation,
            $inner instanceof self => $inner->fault,
            default => MailTemplateFault::RenderFailure,
        };

        return new self($e->getMessage(), $fault, previous: $e);
    }
}
