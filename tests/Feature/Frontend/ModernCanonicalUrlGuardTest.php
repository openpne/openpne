<?php

namespace Tests\Feature\Frontend;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The Modern surface emits canonical /groups/* URLs. The OpenPNE 3 /community* family (also
 * /communityTopic, /communityEvent) survives
 * only as GET redirects for bookmarks, so a Modern page that still POSTs there gets a 405 (the
 * group core rename left two pages behind that way).
 */
class ModernCanonicalUrlGuardTest extends TestCase
{
    public function test_no_modern_page_emits_an_openpne3_community_url(): void
    {
        $offenders = [];
        // Test files are out: back-nav.test.ts uses OpenPNE 3 URLs as history fixtures.
        $files = Finder::create()->files()->in(resource_path('js'))
            ->name(['*.ts', '*.tsx'])->notName(['*.test.ts', '*.test.tsx']);
        foreach ($files as $file) {
            // Quote/backtick-led so a comment naming the URL family does not count.
            if (preg_match_all('#[`\'"]/community(?:Topic|Event)?\b#', $file->getContents(), $matches) > 0) {
                $offenders[] = $file->getRelativePathname().' ('.count($matches[0]).')';
            }
        }

        $this->assertSame([], $offenders, 'Modern source(s) with an OpenPNE 3 /community* URL — link and POST to the canonical /groups/* routes instead.');
    }
}
