<?php

declare(strict_types=1);

namespace App\Mail\Template;

use InvalidArgumentException;
use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Renders a mail-template string with the SAME engine OpenPNE 3 used — sandboxed Twig — so an imported
 * OpenPNE 3 template (incl. admin customizations using `for` / operator `if` / filters, which the
 * OpenPNE 3 admin help documented) renders verbatim. The policy mirrors OpenPNE 3's
 * opTwigSandboxSecurityPolicy, tightened to OpenPNE 4's object-free context.
 *
 * Security model — SSTI-safe only because all three hold together: (1) the sandbox allowlist is narrow,
 * (2) `setStrict(true)` denies the BC-implicit tags/functions, (3) the context is normalized to
 * arrays/scalars so no object reaches an allowed tag/filter. Twig never re-renders a variable's value, so
 * member free text (`{{ 7*7 }}`, `[x](url)`, …) stays literal. HTML escaping is the mail view's job.
 */
class MailTemplateRenderer
{
    private readonly Environment $twig;

    /**
     * @param  bool  $strictVariables  production is false (an absent variable renders empty, matching
     *                                 OpenPNE 3's lenient templates). Tests pass true to turn a body/subject referencing an undeclared
     *                                 variable into a loud error — the drift guard between a template's used variables and its declared
     *                                 set (MailTemplateDriftGuardTest).
     */
    public function __construct(bool $strictVariables = false)
    {
        $policy = new SecurityPolicy(
            allowedTags: ['if', 'for', 'app_url_for'],
            allowedFilters: ['date', 'default', 'encoding'],
            allowedMethods: [],
            allowedProperties: [],
            allowedFunctions: ['app_url_for'],
            allowedTests: [],
        );
        // Opt into Twig 4 behavior: without this, extends/use/block/parent and attribute() are implicitly
        // allowed for back-compat, and (since Twig 3.28) every `is <test>` too. Strict mode denies all of
        // them unless allow-listed; imported OpenPNE 3 templates use no tests, so allowedTests stays empty
        // (both the `constant` test and the `constant()` function are thereby denied).
        $policy->setStrict(true);

        $this->twig = new Environment(new ArrayLoader, [
            'autoescape' => false,
            'strict_variables' => $strictVariables,
            'cache' => false,
        ]);
        $this->twig->addExtension(new SandboxExtension($policy, true));
        $this->twig->addExtension(new MailTemplateTwigExtension);
    }

    /** @param array<string, mixed> $context */
    public function render(string $template, array $context): string
    {
        $normalized = $this->normalize($context);

        try {
            return $this->twig->createTemplate($template)->render($normalized);
        } catch (TwigError $e) {
            // Parse error, sandbox violation (disallowed tag/filter/function, e.g. range/`..`), or an
            // unmapped app_url_for route — surfaced with the fault that tells them apart, so the import
            // preflight can group them without matching on message text.
            throw UnsupportedMailTemplateSyntaxException::fromTwig($e);
        }
    }

    /** @param array<string, mixed> $context */
    public function renderSubject(string $template, array $context): string
    {
        // Collapse to one line: a CR/LF or control char (from a member value or an admin template) must
        // not inject mail headers or break the transport encoder.
        return $this->toSingleLine($this->render($template, $context));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalize(array $context): array
    {
        return array_map($this->normalizeValue(...), $context);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->normalizeValue(...), $value);
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        // Never hand an object to the sandbox (an allowed tag/filter could interact via
        // Iterator/Countable/Stringable). Callers pass arrays/scalars — mirrors OpenPNE 3 filterParameters().
        throw new InvalidArgumentException('Mail template context must be arrays/scalars, got '.get_debug_type($value));
    }

    private function toSingleLine(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
