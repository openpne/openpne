<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every Modern screen that draws a comment's words draws its link card too.
 *
 * The server side of a card is four separate pieces — a column, a write hook, a read trigger, a
 * serializer field — and all four can land with nothing on screen. That shipped once: a payload
 * assertion sees `linkCard` on the row and says nothing about whether anyone renders it. This is the
 * cheap version of the check that would have caught it, and it is a coverage rule rather than a list,
 * so a fourth commented body cannot arrive without either drawing the card or changing this.
 */
class CommentLinkCardCoverageTest extends TestCase
{
    public function test_a_screen_that_draws_comment_words_draws_the_comment_card(): void
    {
        $missing = [];

        foreach ($this->modernPages() as $path => $source) {
            if (! str_contains($source, 'comment.body')) {
                continue;
            }
            if (! str_contains($source, 'comment.linkCard')) {
                $missing[] = $path;
            }
        }

        sort($missing);
        $this->assertSame(
            [],
            $missing,
            'Modern screen(s) drawing a comment body without its link card: '.implode(', ', $missing),
        );
    }

    public function test_the_rule_has_screens_to_check(): void
    {
        // Without this, a rename of the prop would leave the rule above passing over nothing at all.
        $drawing = array_filter($this->modernPages(), fn (string $source): bool => str_contains($source, 'comment.body'));

        $this->assertGreaterThanOrEqual(3, count($drawing), 'The comment screens are no longer found by this rule.');
    }

    /**
     * @return array<string, string> path => source
     */
    private function modernPages(): array
    {
        $root = resource_path('js/pages');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $pages = [];

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx') && ! str_contains($file->getFilename(), '.test.')) {
                $pages[str_replace($root.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }

        return $pages;
    }
}
