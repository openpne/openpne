<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * No literal `Inertia::render('x', [...])` array in app/ may carry a key named after one of
 * HandleInertiaRequests' shared props: page props win the Inertia merge, so the shared value silently
 * disappears for every component on that page. A serializer-built payload is not scanned.
 */
class SharedPropCollisionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_literal_page_prop_shadows_a_shared_prop(): void
    {
        $shared = $this->sharedPropNames();
        $this->assertNotEmpty($shared, 'share() answered no props — the guard has gone stale.');
        $this->assertContains('look', $shared);
        // From parent::share(), which a source scrape of the middleware would never see: a page
        // prop named `errors` would swallow Inertia's validation bag the same silent way.
        $this->assertContains('errors', $shared);

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

    /**
     * Asked of the middleware at runtime rather than scraped from its source, so however share()
     * is reshaped — indentation, extraction into helpers, keys inherited from the parent — the
     * list is what a page actually receives.
     *
     * @return list<string>
     */
    private function sharedPropNames(): array
    {
        $request = Request::create('/dashboard');
        $request->setLaravelSession(app('session.store'));

        return array_keys((new HandleInertiaRequests)->share($request));
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
