<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A screen that draws a subordinate body's words (`comment`, `reply`) must draw its link card too, on
 * both surfaces. Keyed on the row's name rather than a list of files, so the next subordinate body
 * cannot arrive without either drawing the card or changing this rule.
 */
class CommentLinkCardCoverageTest extends TestCase
{
    /** The rows that hang under a page's subject, by the name each surface calls them. */
    private const ROWS = ['comment', 'reply'];

    /**
     * Screens that quote a body without being the page it is read on. A delete confirmation shows
     * what is about to go; a preview card there would offer a way out of the page mid-decision, and
     * it is the same card the page behind it already drew.
     */
    private const QUOTES_ONLY = [
        'diary/comment/delete.blade.php',
        'group-event/comment-delete.blade.php',
        'group-topic/comment-delete.blade.php',
    ];

    public function test_a_modern_screen_that_draws_such_a_body_draws_its_card(): void
    {
        $missing = [];

        foreach ($this->sources('js/pages', '.tsx') as $path => $source) {
            foreach (self::ROWS as $row) {
                if (str_contains($source, "{$row}.body") && ! str_contains($source, "{$row}.linkCard")) {
                    $missing[] = "{$path} ({$row})";
                }
            }
        }

        sort($missing);
        $this->assertSame([], $missing, 'Modern screen(s) drawing a body without its link card: '.implode(', ', $missing));
    }

    public function test_a_classic_screen_that_draws_such_a_body_draws_its_card(): void
    {
        // Classic draws a body through one of two components, and names the card by the record it
        // is for — so the pair to look for is "this row's words" and "this row's card".
        $missing = [];

        foreach ($this->sources('views', '.blade.php') as $path => $source) {
            foreach (self::ROWS as $row) {
                $draws = str_contains($source, "\${$row}->body") || str_contains($source, ":post=\"\${$row}\"");

                if ($draws && ! in_array($path, self::QUOTES_ONLY, true) && ! str_contains($source, ":record=\"\${$row}\"")) {
                    $missing[] = "{$path} (\${$row})";
                }
            }
        }

        sort($missing);
        $this->assertSame([], $missing, 'Classic screen(s) drawing a body without its link card: '.implode(', ', $missing));
    }

    public function test_the_rules_have_screens_to_check(): void
    {
        // Without this, renaming a prop would leave both rules passing over nothing at all — the
        // shape of green this whole guard exists to distrust.
        $modern = 0;
        $classic = 0;

        foreach (self::ROWS as $row) {
            $modern += count(array_filter($this->sources('js/pages', '.tsx'), fn (string $s): bool => str_contains($s, "{$row}.body")));
            $classic += count(array_filter(
                $this->sources('views', '.blade.php'),
                fn (string $s): bool => str_contains($s, "\${$row}->body") || str_contains($s, ":post=\"\${$row}\""),
            ));
        }

        $this->assertGreaterThanOrEqual(4, $modern, 'The Modern screens are no longer found by this rule.');
        $this->assertGreaterThanOrEqual(4, $classic, 'The Classic screens are no longer found by this rule.');
    }

    /**
     * @return array<string, string> path => source
     */
    private function sources(string $under, string $suffix): array
    {
        $root = resource_path($under);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $sources = [];

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix) && ! str_contains($file->getFilename(), '.test.')) {
                $sources[str_replace($root.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }

        return $sources;
    }
}
