<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DB_JOURNAL_MODE reaches Laravel's SQLite connector as a raw PRAGMA value, so what the config file
 * makes of a blank one decides whether the database opens at all: `pragma journal_mode = ` is a
 * syntax error on every connection, and an env template can easily carry the key with nothing after
 * it. Hence the normalisation here, and hence a test for it.
 */
class SqliteJournalModeConfigTest extends TestCase
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

    #[DataProvider('journalModes')]
    public function test_the_sqlite_journal_mode_comes_from_the_environment(?string $value, ?string $expected): void
    {
        // Evaluate the config file against a chosen environment rather than the ambient one, the way
        // SecurityLogChannelTest pins a fallback: set the key, re-evaluate, restore.
        $repository = Env::getRepository();
        $original = $repository->get('DB_JOURNAL_MODE');

        $value === null
            ? $repository->clear('DB_JOURNAL_MODE')
            : $repository->set('DB_JOURNAL_MODE', $value);

        try {
            $config = require base_path('config/database.php');

            $this->assertSame($expected, $config['connections']['sqlite']['journal_mode']);
        } finally {
            $original === null
                ? $repository->clear('DB_JOURNAL_MODE')
                : $repository->set('DB_JOURNAL_MODE', $original);
        }
    }
}
