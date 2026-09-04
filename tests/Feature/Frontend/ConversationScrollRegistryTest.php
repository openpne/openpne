<?php

namespace Tests\Feature\Frontend;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Pins the SELF_PLACING registry in resources/js/lib/chat/opening-scroll.ts to the filesystem in both
 * directions: a registered name must resolve to a page, and every page driving the chat stream must be
 * registered. The name is a string on both sides and the module's unit tests write the same literal,
 * so only the filesystem catches drift.
 */
class ConversationScrollRegistryTest extends TestCase
{
    /**
     * What marks a page as one that places its own opening scroll. The two conversations are the
     * pages that drive the shared message stream, and driving it is what gives a page a foot to open
     * at; a component that only imports the module's error type is not one of them.
     */
    private const SELF_PLACING_MARKER = 'useChatStream(';

    public function test_every_registered_page_exists(): void
    {
        $missing = [];
        foreach ($this->registered() as $name) {
            if (! file_exists(resource_path('js/pages/'.$name.'.tsx'))) {
                $missing[] = $name;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'SELF_PLACING in resources/js/lib/chat/opening-scroll.ts names a page component that does '
            .'not exist. A name that resolves to nothing silently stops declining Inertia\'s scroll.',
        );
    }

    public function test_every_page_that_scrolls_itself_is_registered(): void
    {
        $registered = $this->registered();
        sort($registered);

        $selfPlacing = $this->selfPlacingPages();
        sort($selfPlacing);

        $this->assertSame(
            $selfPlacing,
            $registered,
            'The pages driving the chat stream and the SELF_PLACING registry in '
            .'resources/js/lib/chat/opening-scroll.ts disagree. A conversation missing from the '
            .'registry opens at the top of its history instead of at its newest message.',
        );
    }

    /** The component names the registry declares. @return list<string> */
    private function registered(): array
    {
        $source = (string) file_get_contents(resource_path('js/lib/chat/opening-scroll.ts'));

        $this->assertMatchesRegularExpression('/const SELF_PLACING = new Set\(\[(?<body>[^\]]*)\]\)/', $source);
        preg_match('/const SELF_PLACING = new Set\(\[(?<body>[^\]]*)\]\)/', $source, $m);
        preg_match_all("/'([^']+)'/", $m['body'], $names);

        return $names[1];
    }

    /** The page components that place their own opening scroll, by Inertia's component name. @return list<string> */
    private function selfPlacingPages(): array
    {
        $base = resource_path('js/pages');
        $names = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.tsx')) {
                continue;
            }
            if (! str_contains((string) file_get_contents($file->getPathname()), self::SELF_PLACING_MARKER)) {
                continue;
            }

            $names[] = substr(str_replace($base.'/', '', $file->getPathname()), 0, -strlen('.tsx'));
        }

        return $names;
    }
}
