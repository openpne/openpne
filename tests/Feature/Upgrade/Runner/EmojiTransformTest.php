<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Models\CommunityEvent;
use App\Models\Diary;
use App\Models\DirectMessage;
use App\Models\Group;
use App\Models\Member;
use App\Models\UpgradeState;
use App\Upgrade\Runner\EmojiMap;
use App\Upgrade\Runner\EmojiTransform;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

/**
 * The post-walk emoji pass: every mapped [i:N]/[e:N]/[s:N] code in a member-authored text column
 * becomes Unicode, unmapped and carrier-logo codes stay literal, and a codeless row is left alone.
 * Progress is an id cursor (unmapped codes never drain a predicate), so resuming from a persisted
 * cursor and restarting from 0 both converge without double-converting. MySQL only (REGEXP + utf8mb4
 * preflight), like the rest of the upgrade suite.
 */
class EmojiTransformTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The emoji pass narrows rows with a MySQL REGEXP and preflights utf8mb4.');
        }
    }

    /** @return list<string> */
    private function runTransform(array $tables): array
    {
        $lines = [];
        $ok = (new EmojiTransform)->run($tables, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertTrue($ok);

        return $lines;
    }

    public function test_converts_codes_across_tables_and_columns(): void
    {
        $member = Member::factory()->create(['name' => 'ken[i:1]']);
        $diary = Diary::factory()->create(['title' => 't[i:1]', 'body' => 'b[i:98]']);
        $message = DirectMessage::factory()->create(['subject' => null, 'body' => 'hi[i:136]']);
        $event = CommunityEvent::factory()->create(['open_date_comment' => '[i:1]13:00', 'area' => 'Tokyo[i:98]']);

        $this->runTransform(['members', 'diaries', 'direct_messages', 'community_events']);

        $this->assertSame('ken'.EmojiMap::convert('[i:1]'), $member->fresh()->name);
        $this->assertSame('t'.EmojiMap::convert('[i:1]'), $diary->fresh()->title);
        $this->assertSame('b'.EmojiMap::convert('[i:98]'), $diary->fresh()->body);
        // A null column is skipped, not converted; the sibling column still converts.
        $this->assertNull($message->fresh()->subject);
        $this->assertSame('hi'.EmojiMap::convert('[i:136]'), $message->fresh()->body);
        $this->assertSame(EmojiMap::convert('[i:1]').'13:00', $event->fresh()->open_date_comment);
        $this->assertSame('Tokyo'.EmojiMap::convert('[i:98]'), $event->fresh()->area);

        foreach (['emoji_members', 'emoji_diaries', 'emoji_direct_messages', 'emoji_community_events'] as $key) {
            $this->assertDatabaseHas('openpne4_upgrade_state', ['step_key' => $key, 'status' => UpgradeState::STATUS_COMPLETED]);
        }
    }

    public function test_unmapped_codes_stay_literal_and_codeless_rows_are_untouched(): void
    {
        $unmapped = Diary::factory()->create(['title' => 'keep[i:999]', 'body' => 'logo[i:108]']);
        $plain = Diary::factory()->create(['title' => 'no codes here', 'body' => 'plain body']);
        $plainUpdatedAt = $plain->fresh()->updated_at;

        $this->runTransform(['diaries']);

        // [i:999] has no mapping and [i:108] is an iモード carrier logo with no Unicode equivalent;
        // both survive verbatim (so this row matched the REGEXP but was never rewritten).
        $this->assertSame('keep[i:999]', $unmapped->fresh()->title);
        $this->assertSame('logo[i:108]', $unmapped->fresh()->body);
        // No code at all: never fetched, never written (updated_at is left untouched).
        $this->assertSame('no codes here', $plain->fresh()->title);
        $this->assertEquals($plainUpdatedAt, $plain->fresh()->updated_at);
    }

    public function test_only_changed_rows_are_counted(): void
    {
        Diary::factory()->create(['title' => 'a[i:1]', 'body' => 'plain']);            // rewritten
        Diary::factory()->create(['title' => 'only[i:999]here', 'body' => 'plain']);   // matched, converts to itself
        Diary::factory()->create(['title' => 'nocode', 'body' => 'nocode']);           // never fetched

        $this->runTransform(['diaries']);

        // Only the first row's UPDATE ran: the unmapped-only row produced no changed column, the
        // codeless row never matched the narrowing REGEXP.
        $this->assertSame(1, (int) UpgradeState::query()->where('step_key', 'emoji_diaries')->value('rows_affected'));
    }

    public function test_resume_continues_from_the_cursor_then_a_restart_reconverts_the_rest(): void
    {
        $d1 = Diary::factory()->create(['body' => 'first[i:1]']);
        $d2 = Diary::factory()->create(['body' => 'second[i:1]']);
        $d3 = Diary::factory()->create(['body' => 'third[i:1]']);

        // A crashed run that had already progressed past d2; the resume must pick up only d3.
        UpgradeState::create([
            'step_key' => 'emoji_diaries',
            'status' => UpgradeState::STATUS_RUNNING,
            'metadata' => ['last_id' => $d2->id],
            'started_at' => now(),
        ]);

        $this->runTransform(['diaries']);

        $this->assertSame('first[i:1]', $d1->fresh()->body);                       // before the cursor, untouched
        $this->assertSame('second[i:1]', $d2->fresh()->body);                      // at the cursor, untouched
        $this->assertSame('third'.EmojiMap::convert('[i:1]'), $d3->fresh()->body); // after the cursor, converted

        // Restarting from 0 (a fresh checkpoint) is safe and idempotent: the already-converted d3 no
        // longer matches the REGEXP, and the rows the resume skipped are finally picked up.
        UpgradeState::query()->where('step_key', 'emoji_diaries')->delete();

        $this->runTransform(['diaries']);

        $this->assertSame('first'.EmojiMap::convert('[i:1]'), $d1->fresh()->body);
        $this->assertSame('second'.EmojiMap::convert('[i:1]'), $d2->fresh()->body);
        $this->assertSame('third'.EmojiMap::convert('[i:1]'), $d3->fresh()->body);
    }

    public function test_a_completed_pass_is_skipped(): void
    {
        $diary = Diary::factory()->create(['body' => 'x[i:1]y']);
        $this->runTransform(['diaries']);
        $converted = $diary->fresh()->body;

        $lines = $this->runTransform(['diaries']);

        $this->assertContains('SKIP emoji_diaries: already completed', $lines);
        $this->assertSame($converted, $diary->fresh()->body);
    }

    public function test_only_tables_owned_by_the_run_are_transformed(): void
    {
        $diary = Diary::factory()->create(['body' => 'x[i:1]y']);

        // The run owns no emoji table here, so nothing is transformed and no checkpoint is written.
        $this->runTransform(['friendships']);

        $this->assertSame('x[i:1]y', $diary->fresh()->body);
        $this->assertSame(0, UpgradeState::query()->count());
    }

    public function test_a_db_error_records_failed_status_with_the_message(): void
    {
        // Seed a community already holding the converted name, then one whose code converts onto it: the
        // second row's UPDATE violates the unique index on groups.name and aborts the pass.
        $sun = EmojiMap::convert('[i:1]');
        Group::factory()->create(['name' => "X{$sun}"]);
        $colliding = Group::factory()->create(['name' => 'X[i:1]']);

        $lines = [];
        $ok = (new EmojiTransform)->run(['groups'], function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertFalse($ok);
        $this->assertStringContainsString('FAIL emoji_groups', implode("\n", $lines));

        $state = UpgradeState::query()->where('step_key', 'emoji_groups')->first();
        $this->assertSame(UpgradeState::STATUS_FAILED, $state->status);
        $this->assertNotEmpty($state->error);

        // The failing chunk's transaction rolled back, so the code is still present.
        $this->assertSame('X[i:1]', $colliding->fresh()->name);
    }

    public function test_plan_writes_a_plan_line_and_changes_nothing(): void
    {
        $diary = Diary::factory()->create(['body' => 'x[i:1]y']);
        $lines = [];

        (new EmojiTransform)->plan(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertStringContainsString('PLAN', implode("\n", $lines));
        $this->assertSame('x[i:1]y', $diary->fresh()->body);
        $this->assertSame(0, UpgradeState::query()->count());
    }
}
