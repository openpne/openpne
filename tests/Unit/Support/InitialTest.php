<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Initial;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InitialTest extends TestCase
{
    /** The same table as resources/js/lib/identity-mark.test.ts. */
    public static function names(): array
    {
        return [
            ['My SNS', 'MY'],
            ['Acme', 'AC'],
            ['日本語', '日'],
            ['', '??'],
            ['  ', '??'],
            ["\u{3000}全角空白先頭", '全'],
            ['🎉party', '🎉P'],
            ['a', 'A'],
        ];
    }

    #[DataProvider('names')]
    public function test_the_initial_matches_the_client_twin(string $name, string $initial): void
    {
        $this->assertSame($initial, Initial::of($name));
    }
}
