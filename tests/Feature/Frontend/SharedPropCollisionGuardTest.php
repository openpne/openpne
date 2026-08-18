<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * No page may pass a prop named after one of HandleInertiaRequests' shared props: page props win
 * the Inertia merge, so the shadowed value silently disappears for every component on that page —
 * a page prop `look` once handed the shell an object where it reads a look id, and the whole
 * screen went blank.
 *
 * Text-scrape over the literal `Inertia::render('x', [...])` arrays, like ChromeContextCoverageTest.
 * Blind spot, accepted: a serializer-built payload (`Inertia::render('x', Foo::page(...))`) is not
 * scanned — a shadowing key from those still needs a reviewer's eye.
 */
class SharedPropCollisionGuardTest extends TestCase
{
    public function test_no_literal_page_prop_shadows_a_shared_prop(): void
    {
        $shared = $this->sharedPropNames();
        $this->assertNotEmpty($shared, 'The middleware scrape found no shared props — the pattern went stale.');
        $this->assertContains('look', $shared);

        $offences = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = $file->getContents();
            foreach ($this->renderArrayBlocks($source) as $block) {
                foreach ($this->topLevelKeys($block) as $key) {
                    if (in_array($key, $shared, true)) {
                        $offences[] = "{$file->getRelativePathname()}: page prop '{$key}' shadows the shared prop";
                    }
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", $offences));
    }

    /** @return list<string> */
    private function sharedPropNames(): array
    {
        $middleware = File::get(app_path('Http/Middleware/HandleInertiaRequests.php'));
        // The share() return array's own entries sit at 12 spaces; nested payload keys sit deeper.
        preg_match_all("/^ {12}'(\\w+)' =>/m", $middleware, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The literal array argument of each `Inertia::render('x', [...])`, matched to its closing
     * bracket by depth so nested arrays stay inside their block.
     *
     * @return list<string>
     */
    private function renderArrayBlocks(string $source): array
    {
        $blocks = [];
        $offset = 0;
        while (preg_match("/Inertia::render\\(\\s*'[^']+'\\s*,\\s*\\[/s", $source, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $match[0][1] + strlen($match[0][0]);
            $depth = 1;
            for ($i = $start, $length = strlen($source); $i < $length && $depth > 0; $i++) {
                if ($source[$i] === '[') {
                    $depth++;
                } elseif ($source[$i] === ']') {
                    $depth--;
                }
            }
            $blocks[] = substr($source, $start, $i - $start - 1);
            $offset = $i;
        }

        return $blocks;
    }

    /**
     * Keys at the block's own level: everything inside a nested `[` belongs to that nested array
     * and names whatever that array is about, not a page prop.
     *
     * @return list<string>
     */
    private function topLevelKeys(string $block): array
    {
        $keys = [];
        $depth = 0;
        $length = strlen($block);
        for ($i = 0; $i < $length; $i++) {
            $char = $block[$i];
            if ($char === '[' || $char === '(') {
                $depth++;
            } elseif ($char === ']' || $char === ')') {
                $depth--;
            } elseif ($char === "'" && $depth === 0 && preg_match("/\\G'(\\w+)'\\s*=>/", $block, $match, 0, $i)) {
                $keys[] = $match[1];
                $i += strlen($match[0]) - 1;
            } elseif ($char === "'") {
                // Skip over any other string so brackets inside it cannot skew the depth.
                $i++;
                while ($i < $length && $block[$i] !== "'") {
                    $i += $block[$i] === '\\' ? 2 : 1;
                }
            }
        }

        return $keys;
    }
}
