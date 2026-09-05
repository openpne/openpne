<?php

declare(strict_types=1);

namespace App\Mail\Template;

/**
 * MissingContext is not a renderer verdict: an undefined variable and a genuine runtime fault (`{{ 1 / 0 }}`)
 * are both a Twig RuntimeError and cannot be told apart by type. The import preflight derives it instead,
 * from a template that renders leniently but fails under `strict_variables`.
 */
enum MailTemplateFault: string
{
    case ParseError = 'parse-error';
    case SandboxViolation = 'sandbox-violation';
    case RouteMapFailure = 'route-map-failure';
    case RenderFailure = 'render-failure';
    case MissingContext = 'missing-context';

    public function label(): string
    {
        return match ($this) {
            self::ParseError => 'parse error',
            self::SandboxViolation => 'sandbox violation',
            self::RouteMapFailure => 'route map failure',
            self::RenderFailure => 'render failure',
            self::MissingContext => 'missing context',
        };
    }

    /**
     * Every fault but MissingContext throws on render, so the mail cannot be sent until an admin edits the
     * template; a missing variable only renders empty.
     */
    public function blocksSending(): bool
    {
        return $this !== self::MissingContext;
    }
}
