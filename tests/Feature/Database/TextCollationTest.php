<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Support\Database\IdentifierCollation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TextCollationTest extends TestCase
{
    use RefreshDatabase;

    private const IDENTIFIERS = [
        'members.email', 'registration_tokens.email', 'password_reset_tokens.email',
        'email_change_requests.new_email', 'admin_users.username',
    ];

    /**
     * Every unique index and primary key over a text column, by the kind of value it keeps distinct
     * (docs/internals/text-comparison.md, "Identifiers"): an identifier compares without case, the rest compare binary.
     */
    private const UNIQUE_TEXT = [
        'admin_password_setup_tokens(token)' => 'machine',
        'admin_sessions(id)' => 'machine',
        'admin_users(username)' => 'identifier',
        'banners(name)' => 'constant',
        'cache(key)' => 'machine',
        'cache_locks(key)' => 'machine',
        'email_change_requests(cancel_token)' => 'machine',
        'email_change_requests(token)' => 'machine',
        'failed_jobs(uuid)' => 'machine',
        'files(name)' => 'machine',
        'groups(name)' => 'name',
        'home_issue_items(home_issue_id,section,rank)' => 'constant',
        'home_issue_items(home_issue_id,section,source_type,source_id)' => 'constant',
        'job_batches(id)' => 'machine',
        'link_cards(url_hash)' => 'machine',
        'mail_template_translations(mail_template_id,locale)' => 'constant',
        'mail_templates(key)' => 'constant',
        'member_notification_settings(member_id,kind,channel)' => 'constant',
        'member_preferences(member_id,key)' => 'constant',
        'members(email)' => 'identifier',
        'mfa_reset_requests(token)' => 'machine',
        'navigation_translations(id,lang)' => 'constant',
        'notifications(id)' => 'machine',
        'oauth_access_tokens(id)' => 'machine',
        'oauth_auth_codes(id)' => 'machine',
        'oauth_clients(id)' => 'machine',
        // Typed by a person under the device grant, which OpenPNE 4 leaves off by not registering its route.
        'oauth_device_codes(user_code)' => 'machine',
        'oauth_device_codes(id)' => 'machine',
        'oauth_refresh_tokens(id)' => 'machine',
        'openpne4_upgrade_state(step_key)' => 'constant',
        'passkeys(credential_id)' => 'machine',
        'password_reset_tokens(email)' => 'identifier',
        'personal_access_tokens(token)' => 'machine',
        'profile_option_translations(id,lang)' => 'constant',
        'profile_translations(id,lang)' => 'constant',
        'profiles(name)' => 'name',
        'push_subscriptions(endpoint)' => 'machine',
        'reactions(reactable_type,reactable_id,member_id,emoji)' => 'machine',
        'registration_tokens(email)' => 'identifier',
        'registration_tokens(token)' => 'machine',
        'sessions(id)' => 'machine',
        'sns_settings(key)' => 'constant',
        'term_overrides(name,locale)' => 'constant',
    ];

    private const TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set'];

    public function test_every_unique_text_index_is_listed_with_the_kind_of_value_it_keeps_distinct(): void
    {
        $found = [];

        foreach ($this->tables() as $table) {
            $types = collect(Schema::getColumns($table))->pluck('type_name', 'name');

            foreach (Schema::getIndexes($table) as $index) {
                if (($index['unique'] || $index['primary']) && array_intersect(array_map(fn (string $column) => $types[$column], $index['columns']), self::TEXT_TYPES) !== []) {
                    $found[] = $table.'('.implode(',', $index['columns']).')';
                }
            }
        }

        sort($found);
        $listed = array_keys(self::UNIQUE_TEXT);
        sort($listed);

        $this->assertSame($listed, $found, 'A unique index over a text column was added or removed: list it in UNIQUE_TEXT, and give it IdentifierCollation if a person types the value it keeps distinct.');

        $identifiers = array_keys(array_filter(self::UNIQUE_TEXT, fn (string $kind) => $kind === 'identifier'));
        $this->assertSame(
            ['admin_users(username)', 'members(email)', 'password_reset_tokens(email)', 'registration_tokens(email)'],
            $identifiers,
        );
    }

    public function test_the_identifier_columns_compare_through_the_identifier_collation(): void
    {
        foreach (self::IDENTIFIERS as $identifier) {
            [$table, $column] = explode('.', $identifier);

            $this->assertSame(
                strtolower((string) IdentifierCollation::for(DB::connection())),
                strtolower((string) $this->collationOf($table, $column)),
                $identifier,
            );
        }
    }

    public function test_mariadb_json_columns_keep_their_check(): void
    {
        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('MariaDB only: its JSON is LONGTEXT held to a column check.');
        }

        $this->assertSame(['home_issue_items.stats', 'openpne4_upgrade_state.metadata', 'passkeys.credential'], $this->mariaDbJsonColumns());
    }

    public function test_every_other_text_column_compares_binary(): void
    {
        $binary = $this->binary();
        $json = $this->mariaDbJsonColumns();
        $others = [];

        foreach ($this->tables() as $table) {
            foreach (Schema::getColumns($table) as $column) {
                $name = "{$table}.{$column['name']}";
                if (in_array($column['type_name'], self::TEXT_TYPES, true) && ! in_array($name, [...self::IDENTIFIERS, ...$json], true) && $column['collation'] !== $binary) {
                    $others[$name] = $column['collation'];
                }
            }
        }

        $this->assertSame($binary === null ? [] : ['push_subscriptions.endpoint' => 'ascii_bin'], $others);
    }

    /** Only MariaDB has a uuid type of its own, so only there can this fail, also for a form no migration's text shows, such as foreignIdFor() on a HasUuids model. */
    public function test_no_column_takes_mariadbs_own_uuid_type(): void
    {
        $uuid = [];

        foreach ($this->tables() as $table) {
            foreach (Schema::getColumns($table) as $column) {
                if ($column['type_name'] === 'uuid') {
                    $uuid[] = "{$table}.{$column['name']}";
                }
            }
        }

        $this->assertSame([], $uuid);
    }

    public function test_every_table_defaults_to_the_binary_collation_on_mysql(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MySQL tables carry a default collation.');
        }

        $this->assertSame([], collect(Schema::getTables(DB::connection()->getDatabaseName()))->where('collation', '!=', $this->binary())->pluck('collation', 'name')->all());
    }

    private function binary(): ?string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql' => 'utf8mb4_0900_bin',
            'mariadb' => 'utf8mb4_nopad_bin',
            default => null,
        };
    }

    /** @return list<string> */
    private function tables(): array
    {
        $schema = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? DB::connection()->getDatabaseName() : null;

        return Schema::getTableListing($schema, schemaQualified: false);
    }

    private function collationOf(string $table, string $column): ?string
    {
        return collect(Schema::getColumns($table))->firstWhere('name', $column)['collation'];
    }

    /** @return list<string> */
    private function mariaDbJsonColumns(): array
    {
        if (DB::connection()->getDriverName() !== 'mariadb') {
            return [];
        }

        return collect(DB::select("SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND LEVEL = 'Column' AND CHECK_CLAUSE = CONCAT('json_valid(`', CONSTRAINT_NAME, '`)')"))
            ->map(fn (object $check) => "{$check->TABLE_NAME}.{$check->CONSTRAINT_NAME}")->sort()->values()->all();
    }
}
