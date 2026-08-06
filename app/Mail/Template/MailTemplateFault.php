<?php

declare(strict_types=1);

namespace App\Mail\Template;

/**
 * Why a mail template failed to render, as the import preflight groups it. Assigned where the failure is
 * recognised — the renderer classifies by Twig exception type, so no message-string matching is involved.
 *
 * MissingContext is deliberately NOT a renderer verdict: an undefined variable and a genuine runtime fault
 * (`{{ 1 / 0 }}`) are both a Twig RuntimeError and cannot be told apart by type. The preflight derives it
 * instead, from a template that renders leniently but fails under strict_variables — a difference only an
 * undefined variable or key can produce.
 */
enum MailTemplateFault: string
{
    case ParseError = 'parse-error';
    case SandboxViolation = 'sandbox-violation';
    case RouteMapFailure = 'route-map-failure';
    case RenderFailure = 'render-failure';
    case MissingContext = 'missing-context';

    /** Short operator-facing label for the preflight listing. */
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
     * Whether the fault breaks the mail at send time. Parse errors, sandbox violations and unmapped
     * routes throw on every render, so the mail cannot be sent until an admin edits the template;
     * a missing variable only renders empty. Drives how loudly the preflight words its warning.
     */
    public function blocksSending(): bool
    {
        return $this !== self::MissingContext;
    }
}
