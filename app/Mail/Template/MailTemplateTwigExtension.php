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
        // OpenPNE 3's mobile charset filter; OpenPNE 4 is UTF-8 throughout, so it is a passthrough kept
        // only so a template that still pipes through `|encoding` renders unchanged.
        return [new TwigFilter('encoding', [self::class, 'encoding'])];
    }

    /** $application / $absolute are ignored: OpenPNE 4 URLs are always absolute and single-surface. */
    public static function appUrlFor(string $application, string $uri, bool $absolute = false): string
    {
        return MailUrlMapper::resolve($uri);
    }

    public static function encoding(mixed $value, mixed ...$ignored): mixed
    {
        return $value;
    }
}
