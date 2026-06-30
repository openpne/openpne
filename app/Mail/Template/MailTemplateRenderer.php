<?php

declare(strict_types=1);

namespace App\Mail\Template;

use App\Services\TermService;

/**
 * Renders a mail-template string in OpenPNE 3's template dialect, restricted to the constructs the
 * in-scope (non-digest) NotificationMail templates actually use, so an imported OpenPNE 3 body renders
 * without rewriting ("same 文面"). Supported:
 *   - `{{ dotted.token }}`        — substitution from a flat context map (keys are the OpenPNE 3 token
 *                                   names verbatim, e.g. `op_config.sns_name`, `member.name`).
 *   - `{{ op_term.X }}`           — rewritten to `%X%` and resolved by the existing TermService (the
 *                                   admin-overridable, locale-aware term layer).
 *   - `{% if token %}…{% else %}…{% endif %}` — bare-token truthiness only (nesting allowed); the one
 *                                   construct `requestRegisterURL` needs for its optional inviter/message.
 *   - `{% app_url_for('app','route?…', abs) %}` — mapped to the canonical OpenPNE 4 URL via a bounded
 *                                   route map; OpenPNE 3 query params we no longer use (id/type) are dropped.
 * Anything else ({% for %}, filters, include_component, an unmapped route) raises
 * UnsupportedMailTemplateSyntaxException rather than rendering wrong or sending raw template markup.
 *
 * Context values are substituted RAW. HTML escaping is the view's responsibility
 * (`{!! nl2br(e($body)) !!}`) and the body is never run through Markdown, so a member-controlled value
 * cannot inject markup or links here. Subjects are additionally collapsed to a single line.
 *
 * @phpstan-type Context array<string, scalar|null>
 */
class MailTemplateRenderer
{
    public function __construct(private readonly TermService $terms) {}

    /**
     * Render a body (multi-line preserved).
     *
     * @param  array<string, scalar|null>  $context
     */
    public function render(string $template, array $context, string $locale): string
    {
        $text = $this->resolveUrls($template, $context);
        $text = $this->resolveConditionals($text, $context);
        $text = $this->substituteTokens($text, $context);
        $this->assertNoUnsupportedSyntax($text);

        return $this->terms->replace($text, $locale);
    }

    /**
     * Render a subject and collapse it to a single line — a CR/LF or control char from a member-supplied
     * value or an admin template must not inject mail headers or break the transport encoder.
     *
     * @param  array<string, scalar|null>  $context
     */
    public function renderSubject(string $template, array $context, string $locale): string
    {
        return $this->toSingleLine($this->render($template, $context, $locale));
    }

    /** @param array<string, scalar|null> $context */
    private function resolveUrls(string $text, array $context): string
    {
        $map = $this->urlMap($context);

        return preg_replace_callback('/\{%\s*app_url_for\((.*?)\)\s*%\}/s', function (array $m) use ($map): string {
            // The second argument is the internal uri; its path (before `?`) keys the bounded map.
            if (! preg_match("/,\\s*'([^'?]+)/", $m[1], $route)) {
                throw new UnsupportedMailTemplateSyntaxException('app_url_for: '.trim($m[0]));
            }
            $path = $route[1];
            if (! isset($map[$path])) {
                throw new UnsupportedMailTemplateSyntaxException('app_url_for has no OpenPNE 4 route mapping: '.$path);
            }

            return $map[$path]();
        }, $text) ?? $text;
    }

    /**
     * OpenPNE 3 internal-uri path → canonical OpenPNE 4 URL. Only the in-scope routes are mapped; an
     * unmapped route is rejected (diagnosed) rather than guessed.
     *
     * @param  array<string, scalar|null>  $context
     * @return array<string, callable(): string>
     */
    private function urlMap(array $context): array
    {
        $token = (string) ($context['token'] ?? '');

        return [
            'member/register' => fn (): string => url('/register/'.$token),
            // OpenPNE 3 carried token+id+type; OpenPNE 4's confirm route is token-only (id/type dropped).
            'member/configComplete' => fn (): string => url('/member/config/email/confirm/'.$token),
        ];
    }

    /** @param array<string, scalar|null> $context */
    private function resolveConditionals(string $text, array $context): string
    {
        // Resolve innermost-first: the body pattern forbids a nested `{% if %}`, so each pass collapses
        // the deepest blocks, and repeating handles the nesting `requestRegisterURL` uses.
        $innermost = '/\{%\s*if\s+([\w.]+)\s*%\}((?:(?!\{%\s*if\b).)*?)\{%\s*endif\s*%\}/s';

        for ($guard = 0; preg_match($innermost, $text); $guard++) {
            if ($guard > 1000) {
                throw new UnsupportedMailTemplateSyntaxException('unbalanced {% if %}/{% endif %}');
            }
            $text = preg_replace_callback($innermost, function (array $m) use ($context): string {
                [$then, $else] = $this->splitElse($m[2]);

                return $this->isTruthy($context, $m[1]) ? $then : $else;
            }, $text, 1) ?? $text;
        }

        return $text;
    }

    /** @return array{0: string, 1: string} the then-branch and else-branch (empty when no else). */
    private function splitElse(string $inner): array
    {
        $parts = preg_split('/\{%\s*else\s*%\}/', $inner, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /** @param array<string, scalar|null> $context */
    private function isTruthy(array $context, string $token): bool
    {
        $value = $context[$token] ?? null;

        return $value !== null && $value !== '' && $value !== false;
    }

    /** @param array<string, scalar|null> $context */
    private function substituteTokens(string $text, array $context): string
    {
        return preg_replace_callback('/\{\{\s*(.*?)\s*\}\}/s', function (array $m) use ($context): string {
            $expr = trim($m[1]);
            if (preg_match('/^op_term\.(\w+)$/', $expr, $term)) {
                return '%'.$term[1].'%';
            }
            if (preg_match('/^[\w.]+$/', $expr)) {
                return (string) ($context[$expr] ?? '');
            }
            throw new UnsupportedMailTemplateSyntaxException('unsupported expression: {{ '.$expr.' }}');
        }, $text) ?? $text;
    }

    private function assertNoUnsupportedSyntax(string $text): void
    {
        // After URL + conditional resolution, any remaining tag is `{% for %}`, include_component, or an
        // unbalanced if — none of which the in-scope set uses.
        if (preg_match('/\{%.*?%\}/s', $text, $m)) {
            throw new UnsupportedMailTemplateSyntaxException('unsupported tag: '.trim($m[0]));
        }
    }

    private function toSingleLine(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
