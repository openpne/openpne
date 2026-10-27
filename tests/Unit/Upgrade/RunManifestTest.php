<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\Runner\BoardBumpBackfill;
use App\Upgrade\Runner\ClassicThemeSelection;
use App\Upgrade\Runner\FileBinMigration;
use App\Upgrade\Runner\PasswordWrap;
use App\Upgrade\Runner\RunManifest;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use PHPUnit\Framework\TestCase;

class RunManifestTest extends TestCase
{
    public function test_the_hash_is_a_function_of_the_ordered_phases(): void
    {
        $a = RunManifest::build([new FriendshipUpgrade, new FriendRequestUpgrade], [], [], []);
        $again = RunManifest::build([new FriendshipUpgrade, new FriendRequestUpgrade], [], [], []);
        $reordered = RunManifest::build([new FriendRequestUpgrade, new FriendshipUpgrade], [], [], []);

        $this->assertSame($a->hash(), $again->hash());
        $this->assertNotSame($a->hash(), $reordered->hash());
        $this->assertSame(['FriendshipUpgrade', 'FriendRequestUpgrade'], $a->keys());
    }

    public function test_phases_join_only_when_they_have_a_checkpoint_to_write(): void
    {
        $phases = UpgradeRunner::phases();

        $none = RunManifest::build([], $phases, ['friendships'], ['member_relationship']);
        $this->assertSame([], $none->keys());

        $some = RunManifest::build([], $phases, ['members', 'files', 'group_topics', 'group_topic_comments', 'sns_settings'], ['member', 'sns_config']);
        $this->assertSame(['PasswordWrap', 'EmojiTransform', 'SitePolicyMarkdownTransform', 'BoardBumpBackfill', 'ClassicThemeSelection', 'FileBinMigration'], $some->keys());

        // sns_settings without a step reading sns_config: the theme pass is not applicable.
        $this->assertNotContains('ClassicThemeSelection', RunManifest::build([], $phases, ['sns_settings'], ['member'])->keys());
        $this->assertSame([], (new BoardBumpBackfill)->checkpointKeys(['group_topics'], []), 'a board without its comments is not settled');
        $this->assertSame(['password_wrap_members'], (new PasswordWrap)->checkpointKeys(['members'], []));
        $this->assertSame(['file_id_max_snapshot', 'file_bin_rewire'], (new FileBinMigration)->checkpointKeys(['files'], []));
        $this->assertSame(['file_bin_move'], FileBinMigration::optionalKeys());
        $this->assertSame([], (new ClassicThemeSelection)->checkpointKeys(['members'], ['sns_config']));
    }

    public function test_a_version_bump_changes_the_hash_and_is_named_as_the_difference(): void
    {
        $current = RunManifest::build([new FriendshipUpgrade], UpgradeRunner::phases(), ['members'], []);
        $stored = RunManifest::fromStored(['phases' => array_map(
            static fn (array $p): array => $p['key'] === 'PasswordWrap' ? ['version' => '0'] + $p : $p,
            $current->phases,
        )]);

        $this->assertNotSame($current->hash(), $stored->hash());
        $this->assertSame('`PasswordWrap` (changed)', $current->firstDifference($stored));
    }

    public function test_the_first_difference_names_what_was_added_removed_or_changed(): void
    {
        $two = RunManifest::build([new FriendshipUpgrade, new FriendRequestUpgrade], [], [], []);
        $one = RunManifest::build([new FriendRequestUpgrade], [], [], []);

        $this->assertSame('`FriendshipUpgrade` (added)', $two->firstDifference($one));
        $this->assertSame('`FriendshipUpgrade` (removed)', $one->firstDifference($two));
        $this->assertNull($two->firstDifference(RunManifest::fromStored(json_decode(json_encode($two), true))));
        $this->assertNull(RunManifest::fromStored(['phases' => 'garbled']));
        $this->assertNull(RunManifest::fromStored([]));
    }

    public function test_the_json_round_trips_through_storage(): void
    {
        $manifest = RunManifest::build([new FriendshipUpgrade], UpgradeRunner::phases(), ['members'], []);
        $json = json_decode(json_encode($manifest), true);

        $this->assertSame(1, $json['format']);
        $this->assertSame('sha256', $json['algorithm']);
        $this->assertSame($manifest->hash(), $json['hash']);
        $this->assertSame($manifest->hash(), RunManifest::fromStored($json)->hash());
    }
}
