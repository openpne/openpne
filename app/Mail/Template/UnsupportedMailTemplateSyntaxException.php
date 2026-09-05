<?php

declare(strict_types=1);

namespace App\Mail\Template;

use RuntimeException;
use Throwable;
use Twig\Error\Error as TwigError;
use Twig\Error\SyntaxError;
use Twig\Sandbox\SecurityError;

/**
 * Surfaced rather than dropped or sent raw, so the admin editor can reject a template before it is stored
 * and the import preflight can list it.
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
     * A runtime error wrapping one of ours is Twig re-throwing what `app_url_for` raised, so the inner fault
     * is the real one; anything else runtime stays generic, a strict-variable miss and an honest runtime
     * fault sharing that type.
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
