<?php

namespace Tests\Feature\Frontend;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Guards ContextHeader coverage (default-over-opt-in): every routed Modern component must be
 * classified — either its member-chrome.ts registry entry builds a context crumb, or it is listed in
 * NO_CONTEXT_COMPONENTS with a reason. Without this, a new page lands with no back-navigation and
 * nobody notices. auth/* pages render outside MemberFrame and are exempt.
 */
class ChromeContextCoverageTest extends TestCase
{
    public function test_every_routed_modern_component_is_classified(): void
    {
        $chrome = (string) file_get_contents(resource_path('js/lib/member-chrome.ts'));
        $noContext = $this->noContextComponents($chrome);

        $unclassified = [];
        foreach ($this->routedComponents() as $component) {
            if (str_starts_with($component, 'auth/') || in_array($component, $noContext, true)) {
                continue;
            }
            if (! $this->hasContext($chrome, $component)) {
                $unclassified[] = $component;
            }
        }

        sort($unclassified);
        $this->assertSame(
            [],
            $unclassified,
            'Component(s) with no ContextHeader classification — add a `context:` entry to '
            .'member-chrome.ts (directly or via a *Context( helper) or list in NO_CONTEXT_COMPONENTS: '
            .implode(', ', $unclassified),
        );
    }

    /** Every Inertia component name routed from app/**\/*.php (Inertia::render, plus the slash-form screen() helper). @return list<string> */
    private function routedComponents(): array
    {
        $components = [];
        foreach ($this->phpFiles() as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match_all("/Inertia::render\(\s*'([^']+)'/", $contents, $m)) {
                foreach ($m[1] as $component) {
                    $components[$component] = true;
                }
            }
            // RegistrationController-style screen($request, $classicView, $modernComponent, ...): the
            // modern component is slash-form; a dot-form 3rd arg (FortifyServiceProvider's classic
            // view name) is filtered out, and its own modern component reaches us via Inertia::render.
            if (preg_match_all("/->screen\(\s*\\\$request,\s*'[^']+',\s*'([^']+)'/", $contents, $m)) {
                foreach ($m[1] as $component) {
                    if (str_contains($component, '/')) {
                        $components[$component] = true;
                    }
                }
            }
        }

        return array_keys($components);
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $base = app_path();
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** String literals inside the NO_CONTEXT_COMPONENTS export. @return list<string> */
    private function noContextComponents(string $chrome): array
    {
        if (! preg_match('/NO_CONTEXT_COMPONENTS[^=]*=\s*\[(.*?)\]\s*;/s', $chrome, $m)) {
            return [];
        }
        preg_match_all("/'([^']+)'/", $m[1], $literals);

        return $literals[1];
    }

    /** The component's registry entry (HUB_CHROME or STATIC_CHROME) builds a context crumb. */
    private function hasContext(string $chrome, string $component): bool
    {
        $slice = $this->entrySlice($chrome, $component);

        return $slice !== null
            && (str_contains($slice, 'context:') || preg_match('/\bContext\(/', $slice) === 1 || str_contains($slice, 'CONFIG_CONTEXT'));
    }

    /**
     * The registry entry's source text for one component key: every line from the `'component/name':`
     * declaration up to (not including) the next top-level map key or the map's closing brace. Both
     * maps indent entries by exactly 4 spaces, so this needs no brace-depth parsing.
     */
    private function entrySlice(string $chrome, string $component): ?string
    {
        $lines = explode("\n", $chrome);
        $needle = "    '{$component}':";

        $start = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, $needle)) {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            return null;
        }

        $end = count($lines);
        for ($i = $start + 1; $i < count($lines); $i++) {
            if (preg_match("/^    '[^']+':/", $lines[$i]) === 1 || preg_match('/^};?$/', $lines[$i]) === 1) {
                $end = $i;
                break;
            }
        }

        return implode("\n", array_slice($lines, $start, $end - $start));
    }
}
