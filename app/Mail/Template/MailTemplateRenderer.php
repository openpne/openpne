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
 * Sandboxed Twig, mirroring OpenPNE 3's opTwigSandboxSecurityPolicy, is SSTI-safe only while all three
 * hold: the allowlist stays narrow, `setStrict(true)` denies the BC-implicit tags and functions, and the
 * context is normalized to arrays/scalars so no object reaches an allowed tag. Twig never re-renders a
 * variable's value, so member free text (`{{ 7*7 }}`) stays literal.
 */
class MailTemplateRenderer
{
    private readonly Environment $twig;

    /**
     * @param  bool  $strictVariables  production is false: an absent variable renders empty, matching
     *                                 OpenPNE 3's lenient templates.
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
        // Without this, Twig implicitly allows extends/use/block/parent, attribute() and (since 3.28) every
        // `is <test>` for back-compat, the `constant` test included.
        $policy->setStrict(true);

        $this->twig = new Environment(new ArrayLoader, [
            // HTML escaping is the mail view's job.
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
            // SyntaxError, SecurityError and the RuntimeError an unmapped `app_url_for` raises all extend
            // TwigError, so the fault is classified by type rather than by message text.
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
        // Never hand an object to the sandbox: an allowed tag or filter could interact with it through
        // Iterator, Countable or Stringable.
        throw new InvalidArgumentException('Mail template context must be arrays/scalars, got '.get_debug_type($value));
    }

    private function toSingleLine(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
