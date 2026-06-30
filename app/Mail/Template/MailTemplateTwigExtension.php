<?php

declare(strict_types=1);

namespace App\Mail\Template;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The OpenPNE 3 compatibility surface for the mail-template sandbox: the `app_url_for` helper tag/function
 * and a no-op `encoding` filter. `date`/`default` are Twig built-ins (allowed by the SecurityPolicy).
 */
final class MailTemplateTwigExtension extends AbstractExtension
{
    public function getTokenParsers(): array
    {
        return [new AppUrlForTokenParser];
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('app_url_for', [self::class, 'appUrlFor'])];
    }

    public function getFilters(): array
    {
        return [
            // Override Twig's built-in `date` with OpenPNE 3's semantics (opTwigCoreExtension): a
            // non-numeric value is run through strtotime() first, and an empty/unparseable value falls to
            // the epoch (not "now"/an exception) — so a migrated template formats dates identically.
            new TwigFilter('date', [self::class, 'date']),
            // OpenPNE 3's mobile charset filter; OpenPNE 4 is UTF-8 throughout, so it is a passthrough kept
            // only so a template that still pipes through `|encoding` renders unchanged.
            new TwigFilter('encoding', [self::class, 'encoding']),
        ];
    }

    /** $application / $absolute are ignored: OpenPNE 4 URLs are always absolute and single-surface. */
    public static function appUrlFor(string $application, string $uri, bool $absolute = false): string
    {
        return MailUrlMapper::resolve($uri);
    }

    public static function date(mixed $value, string $format = 'Y-m-d H:i:s'): string
    {
        $string = (string) $value;
        $timestamp = ctype_digit($string) ? (int) $string : strtotime($string);

        return date($format, $timestamp === false ? 0 : $timestamp);
    }

    public static function encoding(mixed $value, mixed ...$ignored): mixed
    {
        return $value;
    }
}
