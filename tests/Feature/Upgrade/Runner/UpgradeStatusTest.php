<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Runner\UpgradeStatus;
use App\Upgrade\StepRegistry;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use App\Upgrade\Steps\MemberUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Driver-agnostic: the status reads the state table and this build's manifest, never the source. */
class UpgradeStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_state_is_not_started(): void
    {
        $status = UpgradeStatus::read($this->runner());

        $this->assertSame('not_started', $status->status);
        $this->assertSame(['FriendshipUpgrade', 'FriendRequestUpgrade', 'MemberBlockUpgrade'], array_column($status->phases, 'key'));
        $this->assertSame(['not_run', 'not_run', 'not_run'], array_column($status->phases, 'status'));
        $this->assertSame('Status: not_started', $status->lines()[0]);
    }

    public function test_a_partial_walk_is_in_progress_with_the_next_and_failed_phases(): void
    {
        $this->stamp($this->runner());
        $this->complete('FriendshipUpgrade', 2);
        UpgradeState::create(['step_key' => 'FriendRequestUpgrade', 'status' => UpgradeState::STATUS_FAILED, 'error' => 'boom']);

        $status = UpgradeStatus::read($this->runner());

        $this->assertSame('in_progress', $status->status);
        $this->assertSame(['next_phase' => 'FriendRequestUpgrade', 'failed_phase' => 'FriendRequestUpgrade', 'legacy' => false], $status->fields);
        $this->assertSame(['completed', 'failed', 'not_run'], array_column($status->phases, 'status'));
        $this->assertTrue($status->phases[0]['definition_matches']);
        $this->assertSame(2, $status->phases[0]['rows']);
    }

    public function test_a_stopped_pass_is_the_next_phase(): void
    {
        $runner = $this->runner([new MemberUpgrade]);
        $this->stamp($runner);
        $this->complete('MemberUpgrade', 5);
        UpgradeState::create(['step_key' => 'password_wrap_members', 'status' => UpgradeState::STATUS_FAILED, 'error' => 'boom']);

        $status = UpgradeStatus::read($runner);

        $this->assertSame('in_progress', $status->status);
        $this->assertSame('PasswordWrap', $status->fields['next_phase']);
        $this->assertSame('PasswordWrap', $status->fields['failed_phase']);
        $this->assertSame(['MemberUpgrade', 'PasswordWrap', 'EmojiTransform'], array_column($status->phases, 'key'));
    }

    public function test_legacy_state_is_in_progress_and_flagged(): void
    {
        UpgradeState::create(['step_key' => UpgradeRunner::EPOCH_KEY, 'status' => UpgradeState::STATUS_COMPLETED, 'metadata' => ['epoch' => UpgradeRunner::NAMING_EPOCH]]);
        $this->complete('FriendshipUpgrade', 2, definition: false);

        $status = UpgradeStatus::read($this->runner());

        $this->assertSame('in_progress', $status->status);
        $this->assertTrue($status->fields['legacy']);
        $this->assertNull($status->phases[0]['definition_matches']);
    }

    public function test_another_epoch_or_another_manifest_is_blocked(): void
    {
        UpgradeState::create(['step_key' => UpgradeRunner::EPOCH_KEY, 'status' => UpgradeState::STATUS_COMPLETED, 'metadata' => ['epoch' => UpgradeRunner::NAMING_EPOCH - 1]]);
        $status = UpgradeStatus::read($this->runner());
        $this->assertSame('blocked', $status->status);
        $this->assertStringContainsString('naming epoch', $status->fields['reason']);
        $this->assertNull($status->fields['blocking_phase']);

        UpgradeState::query()->delete();
        $this->stamp($this->runner([new FriendRequestUpgrade, new MemberBlockUpgrade]));
        $status = UpgradeStatus::read($this->runner());
        $this->assertSame('blocked', $status->status);
        $this->assertSame('FriendshipUpgrade', $status->fields['blocking_phase']);
        $this->assertStringContainsString('`FriendshipUpgrade` (added)', $status->fields['reason']);
    }

    public function test_a_verified_marker_with_every_phase_done_is_completed(): void
    {
        $runner = $this->runner();
        $this->stamp($runner);
        foreach (['FriendshipUpgrade', 'FriendRequestUpgrade', 'MemberBlockUpgrade'] as $key) {
            $this->complete($key, 1);
        }
        $this->marker($runner->manifest()->hash(), verified: true, release: 'abc1234');

        $status = UpgradeStatus::read($runner);

        $this->assertSame('completed', $status->status);
        $this->assertSame(['completed_at' => '2026-10-27T00:00:00+00:00', 'release' => 'abc1234'], $status->fields);
    }

    public function test_a_marker_the_build_cannot_vouch_for_is_completed_unverified(): void
    {
        $runner = $this->runner();
        $this->stamp($runner);
        foreach (['FriendshipUpgrade', 'FriendRequestUpgrade', 'MemberBlockUpgrade'] as $key) {
            $this->complete($key, 1);
        }

        $this->marker(null, verified: false, release: null);
        $status = UpgradeStatus::read($runner);
        $this->assertSame('completed_unverified', $status->status);
        $this->assertStringContainsString('unknown set of steps', $status->fields['reason']);
        $this->assertNull($status->fields['release']);

        UpgradeState::query()->where('step_key', UpgradeRunner::RUN_KEY)->delete();
        $this->marker('0000', verified: true, release: 'abc1234');
        $status = UpgradeStatus::read($runner);
        $this->assertSame('completed_unverified', $status->status);
        $this->assertStringContainsString('different set of steps', $status->fields['reason']);

        // A step this build added since the completion: the marker's hash matches the build that wrote
        // it only by construction here, so the missing checkpoint is what reports it.
        UpgradeState::query()->where('step_key', UpgradeRunner::RUN_KEY)->delete();
        $wider = $this->runner([new MemberUpgrade, new FriendshipUpgrade, new FriendRequestUpgrade, new MemberBlockUpgrade]);
        $this->marker($wider->manifest()->hash(), verified: true, release: 'abc1234');
        $status = UpgradeStatus::read($wider);
        $this->assertSame('completed_unverified', $status->status);
        $this->assertStringContainsString('no completed checkpoint', $status->fields['reason']);
    }

    public function test_a_subset_runners_marker_is_not_completed_for_the_registry(): void
    {
        $subset = $this->runner();
        $this->stamp($subset);
        foreach (['FriendshipUpgrade', 'FriendRequestUpgrade', 'MemberBlockUpgrade'] as $key) {
            $this->complete($key, 1);
        }
        $this->marker($subset->manifest()->hash(), verified: true, release: null);

        $this->assertSame('completed', UpgradeStatus::read($subset)->status);
        $this->assertSame('completed_unverified', UpgradeStatus::read(new UpgradeRunner(new InsertSelectCompiler))->status);
    }

    public function test_the_command_prints_lines_or_json_and_exits_zero(): void
    {
        $this->artisan('openpne:upgrade-status')
            ->expectsOutputToContain('Status: not_started')
            ->assertSuccessful();

        $this->assertSame(0, Artisan::call('openpne:upgrade-status', ['--json' => true]));
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('not_started', $json['status']);
        $this->assertArrayHasKey('site', $json);
        $this->assertNull($json['release']);
        $this->assertSame((new UpgradeRunner(new InsertSelectCompiler))->manifest()->hash(), $json['manifest_hash']);
        $this->assertSame(count(array_filter(StepRegistry::all(), static fn ($s): bool => $s->pendingTargets() === [])), count(array_filter($json['phases'], static fn (array $p): bool => $p['kind'] === 'step')));
    }

    public function test_a_missing_state_table_exits_one(): void
    {
        Schema::drop('openpne4_upgrade_state');

        $this->artisan('openpne:upgrade-status')
            ->expectsOutputToContain('openpne4_upgrade_state')
            ->assertFailed();
    }

    /** @param  list<UpgradeStep>|null  $steps */
    private function runner(?array $steps = null): UpgradeRunner
    {
        return new UpgradeRunner(new InsertSelectCompiler, $steps ?? [new FriendshipUpgrade, new FriendRequestUpgrade, new MemberBlockUpgrade]);
    }

    private function stamp(UpgradeRunner $runner): void
    {
        UpgradeState::create(['step_key' => UpgradeRunner::EPOCH_KEY, 'status' => UpgradeState::STATUS_COMPLETED, 'metadata' => ['epoch' => UpgradeRunner::NAMING_EPOCH, 'manifest' => $runner->manifest(), 'release' => null]]);
    }

    private function complete(string $key, int $rows, bool $definition = true): void
    {
        $step = collect(StepRegistry::all())->first(static fn ($s): bool => class_basename($s) === $key);
        UpgradeState::create([
            'step_key' => $key,
            'status' => UpgradeState::STATUS_COMPLETED,
            'rows_affected' => $rows,
            'finished_at' => now(),
            'metadata' => $definition ? ['definition' => $step->definition()] : null,
        ]);
    }

    private function marker(?string $hash, bool $verified, ?string $release): void
    {
        UpgradeState::create([
            'step_key' => UpgradeRunner::RUN_KEY,
            'status' => UpgradeState::STATUS_COMPLETED,
            'finished_at' => now(),
            'metadata' => ['verified' => $verified, 'manifest_hash' => $hash, 'release' => $release, 'completed_at' => '2026-10-27T00:00:00+00:00'],
        ]);
    }
}
