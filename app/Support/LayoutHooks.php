<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/**
 * Registry of the HTML a package draws at the shells' fixed positions (docs/internals/layout-hooks.md).
 * Shared by attribute, not by a provider binding, so a package registering in register() keeps its instance.
 */
#[Singleton]
final class LayoutHooks
{
    /** @var array<string, list<Closure(): (string|Htmlable|null)>> */
    private array $renderers = [];

    /** @param Closure(): (string|Htmlable|null) $render */
    public function register(LayoutHook $hook, Closure $render): void
    {
        $this->renderers[$hook->value][] = $render;
    }

    /** Raw HTML; a renderer that throws is reported and contributes nothing, so the Classic 4xx page still renders. */
    public function render(LayoutHook $hook): string
    {
        $html = '';
        foreach ($this->renderers[$hook->value] ?? [] as $render) {
            try {
                $part = $render();
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            $html .= $part instanceof Htmlable ? $part->toHtml() : (string) $part;
        }

        return $html;
    }

    /** @return array<string, string> hook value => HTML, the client-rendered Modern positions that render non-empty */
    public function clientMap(): array
    {
        $map = [];
        foreach (LayoutHook::cases() as $hook) {
            if (! $hook->isClientRendered()) {
                continue;
            }
            $html = $this->render($hook);
            if ($html !== '') {
                $map[$hook->value] = $html;
            }
        }

        return $map;
    }
}
