<?php

namespace Tests\Feature\Frontend;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * An element drawn at zero height is drawn there so that it costs the page nothing — a floating pill
 * over the conversation, an overlay that must not move what it floats over. Zero height does not give
 * that on its own: every member page is a `<main>` carrying a `space-y-*` from
 * `components/member-frame.tsx`, and Tailwind v4 spends that as a `margin-bottom` on every child but
 * the last. A wrapper written `h-0` and nothing else therefore takes 16px, permanently if it is
 * mounted permanently, which is the opposite of what `h-0` was reached for.
 *
 * Found by measuring rather than reading (the jump-to-latest pill grew the page by 16px each time it
 * appeared, on both conversation surfaces), so the guard is here to make the finding outlive the fix.
 *
 * What it asks for is that the bottom margin be **stated**, not that it be zero. `h-0 mb-0` passes;
 * so would a deliberate `h-0 mb-4`. Only silence fails, because silence is where the page's rhythm
 * answers for you. If a future `h-0` is not a child of a `space-y-*` container it inherits no margin
 * and the rule does not bind — but `mb-0` costs nothing there, so satisfying the guard is cheaper
 * than arguing with it.
 *
 * Per class string rather than per file: the two have to travel together to be read together, and a
 * file-wide check would pass on an `mb-0` written for something else entirely.
 */
class ZeroHeightMarginGuardTest extends TestCase
{
    /** `h-0` exactly — not `h-0.5`, and not the `h-0` inside an arbitrary value. */
    private const ZERO_HEIGHT = '/(?<![\w.\-])h-0(?![\w.\-])/';

    /** Any bottom margin, stated at any breakpoint: `mb-0`, `lg:mb-4`, `mb-[3px]`. */
    private const STATES_MARGIN = '/(?<![\w.\-])(?:[a-z-]+:)?mb-/';

    /**
     * Quoted strings on one line. Class strings are written on one line here, and staying on one is
     * what keeps prose out: an apostrophe in a comment opens a "string" that runs to the next one, and
     * across lines that swallows whole blocks — enough to report an unreadable blob, and enough to
     * pair an `h-0` in one place with an `mb-` in another and pass.
     */
    private const CLASS_STRINGS = '/([\'"])((?:(?!\1)[^\r\n])*)\1/';

    public function test_a_zero_height_class_string_states_its_bottom_margin(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $relative => $contents) {
            foreach (explode("\n", $contents) as $number => $line) {
                preg_match_all(self::CLASS_STRINGS, $line, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $string = $match[2];
                    if (preg_match(self::ZERO_HEIGHT, $string) !== 1) {
                        continue;
                    }
                    if (preg_match(self::STATES_MARGIN, $string) !== 1) {
                        $offenders[] = $relative.':'.($number + 1).'  '.$string;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'A class string draws something at zero height without saying what margin it has.',
            'The page frame puts a margin under every child but the last, so an unstated one is 16px —',
            'and something drawn at zero height that occupies 16px is not doing what it was written to do.',
            'Add `mb-0` (or the margin you do want) to the same class string.',
            '',
            ...$offenders,
        ]));
    }

    /** @return array<string, string> */
    private function sourceFiles(): array
    {
        $base = resource_path('js');
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $name = $file->getFilename();
            if (! $file->isFile() || ! str_ends_with($name, '.tsx') || str_ends_with($name, '.test.tsx')) {
                continue;
            }
            $files[str_replace($base.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
        }

        return $files;
    }
}
