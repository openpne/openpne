<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\Runner\UncopiedSettingsNotice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UncopiedSettingsNoticeTest extends TestCase
{
    /** @return array<string, array{string, int|null}> */
    public static function openPne3Sizes(): array
    {
        return [
            'the shipped default' => ['300K', 300],
            'megabytes' => ['5M', 5120],
            'lowercase unit' => ['2m', 2048],
            'bare bytes' => ['307200', 300],
            'bare bytes round up to the kilobyte that holds them' => ['307201', 301],
            'zero is not a size' => ['0', null],
            'a decimal OpenPNE 3 read as its integer prefix is refused rather than guessed' => ['1.5M', null],
            'words are not a size' => ['abc', null],
            'a gigabyte suffix OpenPNE 3 did not scale' => ['1G', null],
        ];
    }

    #[DataProvider('openPne3Sizes')]
    public function test_an_openpne3_image_max_filesize_is_read_as_kilobytes(string $value, ?int $kilobytes): void
    {
        $this->assertSame($kilobytes, UncopiedSettingsNotice::kilobytes($value));
    }
}
