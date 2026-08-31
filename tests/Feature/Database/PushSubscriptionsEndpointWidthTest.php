<?php

namespace Tests\Feature\Database;

use App\Rules\PushEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The endpoint widening round-trips with its unique index in place, and the rows a target column
 * could not hold go before the column changes. The column itself only moves on MySQL — SQLite
 * declares a bare varchar — so the width and charset are pinned on that lane alone.
 */
class PushSubscriptionsEndpointWidthTest extends TestCase
{
    use RefreshDatabase;

    private const UNIQUE = 'push_subscriptions_endpoint_unique';

    protected function tearDown(): void
    {
        // DDL commits the test transaction on MySQL, so the rows would otherwise outlive the test.
        DB::table('push_subscriptions')->delete();

        parent::tearDown();
    }

    public function test_the_widening_round_trips_with_the_unique_index_in_place(): void
    {
        $migration = $this->migration();

        // RefreshDatabase has already run up(). The width is the rule's bound: widening one without
        // the other turns a 422 into a 1406.
        $this->assertContains(self::UNIQUE, $this->indexNames());
        $this->assertEndpointColumn(PushEndpoint::MAX_LENGTH, 'ascii_bin');

        try {
            $migration->down();
            $this->assertContains(self::UNIQUE, $this->indexNames());
            $this->assertEndpointColumn(500, 'utf8mb4_bin');
        } finally {
            // Always back to up(): DDL commits, so a failure here would otherwise leave the process's
            // remaining tests on the old column.
            $migration->up();
        }

        $this->assertContains(self::UNIQUE, $this->indexNames());
        $this->assertEndpointColumn(PushEndpoint::MAX_LENGTH, 'ascii_bin');
    }

    public function test_a_row_the_rule_refuses_goes_before_the_column_changes(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->insert('https://push.example.com/kept');
        $this->insert('https://push.example.com/送信');
        $this->insert("https://push.example.com/with space");

        $migration->up();

        $this->assertSame(['https://push.example.com/kept'], DB::table('push_subscriptions')->pluck('endpoint')->all());
    }

    public function test_a_row_wider_than_the_old_column_goes_on_the_way_back(): void
    {
        $migration = $this->migration();
        $this->insert('https://push.example.com/kept');
        $this->insert(str_pad('https://push.example.com/', PushEndpoint::MAX_LENGTH, 'x'));

        try {
            $migration->down();
            $this->assertSame(['https://push.example.com/kept'], DB::table('push_subscriptions')->pluck('endpoint')->all());
        } finally {
            $migration->up();
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_31_000001_widen_push_subscriptions_endpoint.php');
    }

    private function insert(string $endpoint): void
    {
        DB::table('push_subscriptions')->insert([
            'subscribable_type' => 'member',
            'subscribable_id' => 1,
            'endpoint' => $endpoint,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        return array_map(fn (array $index): string => $index['name'], Schema::getIndexes('push_subscriptions'));
    }

    private function assertEndpointColumn(int $length, string $collation): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $column = collect(Schema::getColumns('push_subscriptions'))->firstWhere('name', 'endpoint');

        $this->assertSame("varchar({$length})", $column['type']);
        $this->assertSame($collation, $column['collation']);
    }
}
