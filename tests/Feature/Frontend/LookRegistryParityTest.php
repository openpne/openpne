<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Support\Look;
use Tests\TestCase;

/**
 * A look is declared twice — App\Support\Look picks the page components, the LOOKS registry in
 * member-chrome.ts picks the shell's chrome — so the two key sets must be equal. Drift ships a look
 * with half its answers.
 */
class LookRegistryParityTest extends TestCase
{
    public function test_the_client_registry_declares_exactly_the_looks_php_does(): void
    {
        $expected = array_column(Look::cases(), 'value');
        sort($expected);

        $actual = $this->clientLooks();
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            'The LOOKS registry in resources/js/lib/member-chrome.ts and App\Support\Look disagree on '
            .'which looks exist — every look needs a row in both.',
        );
    }

    /** The keys of the LOOKS object literal. @return list<string> */
    private function clientLooks(): array
    {
        $chrome = (string) file_get_contents(resource_path('js/lib/member-chrome.ts'));

        $this->assertMatchesRegularExpression('/export const LOOKS = \{(?<body>.*?)\n\} as const/s', $chrome);
        preg_match('/export const LOOKS = \{(?<body>.*?)\n\} as const/s', $chrome, $m);
        preg_match_all('/^\s{4}(\w+):/m', $m['body'], $keys);

        return $keys[1];
    }
}
