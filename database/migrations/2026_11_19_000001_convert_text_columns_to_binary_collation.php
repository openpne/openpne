<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * See docs/internals/text-comparison.md, "Converting an existing site".
 */
return new class extends Migration
{
    public const COLLATION = 'utf8mb4_0900_bin';

    /** MariaDB 10.11 has no utf8mb4_0900_bin; this NO PAD binary collation compares the same (docs/internals/text-comparison.md, "MariaDB"). */
    public const MARIADB_COLLATION = 'utf8mb4_nopad_bin';

    /** What IdentifierCollation named when this was released, written out (like the whitespace check) so a later change to it leaves this migration as it was. */
    private const IDENTIFIER_COLLATION = ['mysql' => 'utf8mb4_0900_as_ci', 'mariadb' => 'utf8mb4_uca1400_nopad_as_ci', 'sqlite' => 'NOCASE'];

    /** Named rather than discovered: an upgraded site keeps OpenPNE 3's tables, file_bin among them, in the same database. */
    public const TABLES = [
        'admin_sessions', 'admin_users', 'banner_images', 'banner_use_images', 'banners', 'cache',
        'cache_locks', 'diaries', 'diary_comment_images', 'diary_comments', 'diary_images',
        'direct_message_files', 'direct_message_recipients', 'direct_messages', 'email_change_requests',
        'failed_jobs', 'files', 'friend_requests', 'friendships', 'gadget_configs', 'gadgets',
        'group_categories', 'group_event_comment_images', 'group_event_comments', 'group_event_images',
        'group_event_members', 'group_events', 'group_join_requests', 'group_members',
        'group_message_images', 'group_message_mentions', 'group_messages', 'group_topic_comment_images',
        'group_topic_comments', 'group_topic_images', 'group_topics', 'groups', 'home_issue_items',
        'home_issues', 'job_batches', 'jobs', 'link_cards', 'mail_template_translations',
        'mail_templates', 'member_blocks', 'member_images', 'member_notification_settings',
        'member_preferences', 'member_profiles', 'members', 'mfa_reset_requests', 'migrations',
        'navigation_translations', 'navigations', 'notifications', 'oauth_access_tokens',
        'oauth_auth_codes', 'oauth_clients', 'oauth_device_codes', 'oauth_refresh_tokens',
        'openpne4_upgrade_state', 'passkeys', 'password_reset_tokens', 'personal_access_tokens',
        'profile_option_translations', 'profile_options', 'profile_translations', 'profiles',
        'push_subscriptions', 'reactions', 'registration_tokens', 'sessions', 'sns_settings',
        'term_overrides', 'timeline_post_images', 'timeline_post_mentions', 'timeline_post_tags',
        'timeline_posts',
    ];

    /** @var array<string, array{string, bool}> table => its identifier column, and whether a unique index or primary key holds it */
    public const IDENTIFIERS = [
        'members' => ['email', true],
        'registration_tokens' => ['email', true],
        'password_reset_tokens' => ['email', true],
        'email_change_requests' => ['new_email', false],
        'admin_users' => ['username', true],
    ];

    public function up(): void
    {
        if (! $this->converts()) {
            return;
        }

        $this->refuseValuesTheIdentifierCollationCannotHold();

        if ($this->mysqlFamily()) {
            $this->alterMysql(self::IDENTIFIER_COLLATION[DB::getDriverName()]);
        } else {
            $this->rebuildSqlite(self::IDENTIFIER_COLLATION['sqlite']);
        }
    }

    /** The tables stay binary: going back to utf8mb4_unicode_ci would merge names it treats as one. */
    public function down(): void
    {
        if (! $this->converts()) {
            return;
        }

        if ($this->mysqlFamily()) {
            $this->alterMysql($this->collation());
        } else {
            $this->rebuildSqlite(null);
        }
    }

    private function converts(): bool
    {
        return match (DB::getDriverName()) {
            'mysql' => DB::connection()->getConfig('collation') === self::COLLATION,
            'mariadb' => DB::connection()->getConfig('collation') === self::MARIADB_COLLATION,
            'sqlite' => true,
            default => false,
        };
    }

    private function mysqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function collation(): string
    {
        return DB::getDriverName() === 'mariadb' ? self::MARIADB_COLLATION : self::COLLATION;
    }

    private function refuseValuesTheIdentifierCollationCannotHold(): void
    {
        $collation = self::IDENTIFIER_COLLATION[DB::getDriverName()];
        $grammar = DB::getQueryGrammar();
        $problems = [];

        foreach (self::IDENTIFIERS as $table => [$column, $unique]) {
            if (! $unique) {
                continue;
            }

            $hasId = Schema::hasColumn($table, 'id');
            $groups = DB::table($table)
                ->selectRaw($hasId ? 'GROUP_CONCAT(id) AS ids' : 'COUNT(*) AS n')
                ->whereNotNull($column)
                ->groupByRaw($grammar->wrap($column).' COLLATE '.$collation)
                ->havingRaw('COUNT(*) > 1')
                ->get();

            if ($groups->isNotEmpty()) {
                $problems[] = $hasId
                    ? "{$table}.{$column} holds values the new comparison treats as one (ids ".$this->ids($groups->flatMap(fn (object $group) => explode(',', (string) $group->ids))).')'
                    : "{$table}.{$column} holds values the new comparison treats as one ({$groups->sum('n')} rows; these are password reset requests, which expire, so deleting them is safe)";
            }

            $rows = DB::table($table)->whereNotNull($column);
            $edged = ($hasId ? $rows->select(['id', $column])->lazyById(1000) : $rows->select([$column])->cursor())
                ->filter(fn (object $row) => Str::trim((string) $row->{$column}) !== (string) $row->{$column});

            if ($edged->isNotEmpty()) {
                $problems[] = $hasId
                    ? "{$table}.{$column} holds values with whitespace at either end, which no one can type (ids ".$this->ids($edged->pluck('id')).')'
                    : "{$table}.{$column} holds values with whitespace at either end, which no one can type ({$edged->count()} rows; these are password reset requests, which expire, so deleting them is safe)";
            }
        }

        if ($problems !== []) {
            throw new RuntimeException("Nothing was converted. Fix these rows and run the migration again:\n- ".implode("\n- ", $problems));
        }
    }

    /** @param  iterable<int|string>  $ids */
    private function ids(iterable $ids): string
    {
        return collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->implode(', ');
    }

    /** Every statement is built before the first runs: MySQL does not roll DDL back, and a column this cannot copy must stop it before anything changed. */
    private function alterMysql(string $identifierCollation): void
    {
        $grammar = DB::getQueryGrammar();
        $collation = $this->collation();
        $mariaDb = DB::getDriverName() === 'mariadb';
        $tables = collect(DB::select(
            'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? AND TABLE_NAME IN ('.implode(', ', array_fill(0, count(self::TABLES), '?')).') ORDER BY TABLE_NAME',
            ['BASE TABLE', ...self::TABLES],
        ))->pluck('TABLE_COLLATION', 'TABLE_NAME');
        $columns = collect(DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, EXTRA, GENERATION_EXPRESSION, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND CHARACTER_SET_NAME = ? AND TABLE_NAME IN ('.implode(', ', array_fill(0, count(self::TABLES), '?')).') ORDER BY TABLE_NAME, ORDINAL_POSITION',
            ['utf8mb4', ...self::TABLES],
        ))->groupBy('TABLE_NAME');
        $checks = $mariaDb ? collect(DB::select(
            'SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND LEVEL = ?',
            ['Column'],
        ))->mapWithKeys(fn (object $check) => ["{$check->TABLE_NAME}.{$check->CONSTRAINT_NAME}" => $check->CHECK_CLAUSE]) : collect();

        $statements = [];

        foreach ($tables as $table => $tableCollation) {
            $modify = [];

            foreach ($columns->get($table, []) as $column) {
                $check = $checks->get("{$table}.{$column->COLUMN_NAME}");

                // MariaDB's JSON is LONGTEXT held to a column CHECK that MODIFY would drop; MySQL's JSON has no character set and is never listed.
                if ($check === 'json_valid('.$grammar->wrap($column->COLUMN_NAME).')') {
                    continue;
                }

                if ($check !== null) {
                    throw new RuntimeException("Nothing was converted: {$table}.{$column->COLUMN_NAME} has a check this migration does not copy.");
                }

                $wanted = (self::IDENTIFIERS[$table][0] ?? null) === $column->COLUMN_NAME ? $identifierCollation : $collation;

                if ($column->COLLATION_NAME === $wanted) {
                    continue;
                }

                // MariaDB reports a plain column's GENERATION_EXPRESSION as NULL where MySQL reports ''.
                if ($column->EXTRA !== '' || ($column->GENERATION_EXPRESSION ?? '') !== '' || $column->COLUMN_COMMENT !== '') {
                    throw new RuntimeException("Nothing was converted: {$table}.{$column->COLUMN_NAME} has an attribute, a generation expression or a comment this migration does not copy.");
                }

                $modify[] = sprintf(
                    'MODIFY %s %s CHARACTER SET utf8mb4 COLLATE %s %s%s',
                    $grammar->wrap($column->COLUMN_NAME),
                    $column->COLUMN_TYPE,
                    $wanted,
                    $column->IS_NULLABLE === 'YES' ? 'NULL' : 'NOT NULL',
                    $this->default($column->COLUMN_DEFAULT, $mariaDb),
                );
            }

            if ($modify === [] && $tableCollation === $collation) {
                continue;
            }

            $statements[] = sprintf(
                'ALTER TABLE %s %s',
                $grammar->wrapTable($table),
                implode(', ', ['DEFAULT CHARACTER SET utf8mb4 COLLATE '.$collation, ...$modify]),
            );
        }

        foreach ($statements as $statement) {
            DB::statement($statement);
        }
    }

    /** MariaDB reports COLUMN_DEFAULT as the SQL literal (quoted, and NULL as the word) where MySQL reports the value itself. */
    private function default(?string $default, bool $mariaDb): string
    {
        if ($default === null || ($mariaDb && $default === 'NULL')) {
            return '';
        }

        return ' DEFAULT '.($mariaDb ? $default : DB::getPdo()->quote($default));
    }

    /**
     * Foreign keys go off before the transaction opens, since SQLite ignores the pragma inside one and
     * the rebuild's DROP TABLE would cascade. The rebuild drops the tables' triggers and resets their AUTOINCREMENT high-water mark to the highest id left, so both are read first and put back.
     */
    private function rebuildSqlite(?string $collation): void
    {
        $tables = array_keys(self::IDENTIFIERS);
        $triggers = DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', $tables)->orderBy('name')->get(['name', 'sql']);
        $sequences = DB::table('sqlite_sequence')->whereIn('name', $tables)->pluck('seq', 'name');
        $foreignKeys = (bool) DB::scalar('PRAGMA foreign_keys');

        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($tables, $collation, $triggers, $sequences) {
                foreach ($tables as $table) {
                    Schema::table($table, function (Blueprint $blueprint) use ($table, $collation) {
                        $this->identifier($blueprint, $table)->collation($collation)->change();
                    });
                }

                foreach ($triggers as $trigger) {
                    DB::statement('DROP TRIGGER IF EXISTS '.DB::getQueryGrammar()->wrap($trigger->name));
                    DB::unprepared($trigger->sql);
                }

                foreach ($sequences as $table => $seq) {
                    DB::table('sqlite_sequence')->where('name', $table)->delete();
                    DB::table('sqlite_sequence')->insert(['name' => $table, 'seq' => $seq]);
                }
            });
        } finally {
            if ($foreignKeys) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }

    /** change() writes the whole column, so each keeps the definition its create migration gave it. */
    private function identifier(Blueprint $blueprint, string $table): ColumnDefinition
    {
        return match ($table) {
            'members' => $blueprint->string('email')->nullable(),
            'registration_tokens', 'password_reset_tokens' => $blueprint->string('email'),
            'email_change_requests' => $blueprint->string('new_email'),
            'admin_users' => $blueprint->string('username', 64),
        };
    }
};
