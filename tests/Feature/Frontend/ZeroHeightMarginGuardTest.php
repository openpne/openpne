<?php

namespace Tests\Feature\Frontend;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A `space-y-*` on every member page's `<main>` (components/member-frame.tsx) becomes a margin-bottom
 * on every child but the last under Tailwind v4, so a class string written `h-0` alone still takes
 * 16px. The bottom margin must be stated in the same class string, not be zero: `h-0 mb-4` passes,
 * silence fails.
 */
class ZeroHeightMarginGuardTest extends TestCase
{
    /** `h-0` exactly — not `h-0.5`, and not the `h-0` inside an arbitrary value. */
    private const ZERO_HEIGHT = '/(?<![\w.\-])h-0(?![\w.\-])/';

    /** Any bottom margin, stated at any breakpoint: `mb-0`, `lg:mb-4`, `mb-[3px]`. */
    private const STATES_MARGIN = '/(?<![\w.\-])(?:[a-z-]+:)?mb-/';

    /**
     * Matched within one line: an apostrophe in a comment opens a "string" that, across lines, would
     * swallow whole blocks and pair an `h-0` in one place with an `mb-` in another.
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
