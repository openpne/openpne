<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Both variables reach Laravel's SQLite connector as raw PRAGMA values, and SQLite is lenient about
 * what it accepts, so what the config file makes of a value it was not given properly is the whole
 * guard. A blank journal mode would build `pragma journal_mode = ` and fail every connection; a
 * mistyped synchronous level would resolve — silently, and to something less durable than the default
 * it replaced.
 */
class SqlitePragmaConfigTest extends TestCase
{
    /** @return array<string, array{0: string|null, 1: string|null}> */
    public static function journalModes(): array
    {
        return [
            'unset leaves SQLite its own default' => [null, null],
            'blank reads as unset' => ['', null],
            'a mode passes through' => ['WAL', 'WAL'],
        ];
    }

    /** @return array<string, array{0: string|null, 1: string|null}> */
    public static function synchronousLevels(): array
    {
        return [
            'unset leaves SQLite its own FULL' => [null, null],
            'blank reads as unset' => ['', null],
            'a level passes through' => ['NORMAL', 'NORMAL'],
            'case does not matter' => ['normal', 'NORMAL'],
            // SQLite's own numbering. OFF is 0, so it must survive being falsy.
            'a number names its level' => ['0', 'OFF'],
            // Passed through, SQLite would read these as NORMAL and OFF — quieter and less durable
            // than the FULL they replaced, with nothing said about it.
            'a typo reads as unset, not as the level SQLite would guess' => ['NORAML', null],
            'a number out of range reads as unset' => ['7', null],
        ];
    }

    #[DataProvider('journalModes')]
    public function test_the_journal_mode_comes_from_the_environment(?string $value, ?string $expected): void
    {
        $this->assertConfigured('DB_JOURNAL_MODE', 'journal_mode', $value, $expected);
    }

    #[DataProvider('synchronousLevels')]
    public function test_the_synchronous_level_comes_from_the_environment(?string $value, ?string $expected): void
    {
        $this->assertConfigured('DB_SYNCHRONOUS', 'synchronous', $value, $expected);
    }

    /**
     * Evaluate the config file against a chosen environment rather than the ambient one, the way
     * SecurityLogChannelTest pins a fallback: set the variable, re-evaluate, restore.
     */
    private function assertConfigured(string $variable, string $key, ?string $value, ?string $expected): void
    {
        $repository = Env::getRepository();
        $original = $repository->get($variable);

        $value === null ? $repository->clear($variable) : $repository->set($variable, $value);

        try {
            $config = require base_path('config/database.php');

            $this->assertSame($expected, $config['connections']['sqlite'][$key]);
        } finally {
            $original === null ? $repository->clear($variable) : $repository->set($variable, $original);
        }
    }
}
