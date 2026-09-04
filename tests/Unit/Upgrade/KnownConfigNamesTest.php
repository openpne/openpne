<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Support\PreferenceKey;
use App\Upgrade\StepRegistry;
use App\Upgrade\Steps\GroupUpgrade;
use App\Upgrade\Steps\MemberNotificationSettingUpgrade;
use App\Upgrade\Steps\MemberUpgrade;
use App\Upgrade\UpgradeStep;
use PHPUnit\Framework\TestCase;

/**
 * The recognised-name sets the preflight subtracts the live source from. A name missing here is
 * reported to the operator as an unrecognised custom config, so the sets must cover every name a
 * step actually migrates — including the ones the disposition maps describe only as a family.
 */
class KnownConfigNamesTest extends TestCase
{
    public function test_member_config_names_are_literal(): void
    {
        foreach (StepRegistry::knownMemberConfigNames() as $name) {
            $this->assertStringNotContainsString('*', $name, "`{$name}` is a family, not a name the source can hold");
        }
    }

    public function test_member_config_names_cover_the_migrated_preference_keys(): void
    {
        $known = StepRegistry::knownMemberConfigNames();

        foreach (PreferenceKey::upgradableCases() as $key) {
            $this->assertContains($key->op3SourceName(), $known);
        }
    }

    public function test_member_config_names_cover_every_importable_notification_key(): void
    {
        $known = StepRegistry::knownMemberConfigNames();

        foreach (NotificationKind::importableCases() as $kind) {
            foreach (NotificationChannel::cases() as $channel) {
                $this->assertContains($kind->op3ConfigName($channel), $known);
            }
        }
    }

    /**
     * A kind OpenPNE 4 added itself has no source key, so it can neither widen the recognised-name
     * set (every extra name there is one the preflight stops reporting) nor be a target the step
     * writes.
     */
    public function test_a_native_notification_kind_stays_out_of_the_upgrade(): void
    {
        $importable = NotificationKind::importableCases();
        $native = array_values(array_filter(
            NotificationKind::cases(),
            static fn (NotificationKind $kind): bool => ! in_array($kind, $importable, true),
        ));
        $this->assertNotEmpty($native, 'no native kind registered — this guard now proves nothing');

        $step = new MemberNotificationSettingUpgrade;
        $sql = ($step->filter() ?? '').' '.implode(' ', array_map(
            static fn ($column): string => (string) $column->expr,
            $step->columns(),
        ));

        foreach ($native as $kind) {
            $this->assertStringNotContainsString($kind->value, $sql, "{$kind->value} is a target the upgrade can write");
        }

        $sendKeys = array_filter(
            StepRegistry::knownMemberConfigNames(),
            static fn (string $name): bool => str_starts_with($name, 'is_send_'),
        );
        $this->assertCount(
            count($importable) * count(NotificationChannel::cases()),
            $sendKeys,
            'the recognised is_send_ family must be exactly the importable kinds’ two keys each',
        );
    }

    public function test_member_config_names_cover_the_documented_dispositions(): void
    {
        $known = StepRegistry::knownMemberConfigNames();

        foreach (array_keys(StepRegistry::memberConfigDispositions()) as $name) {
            if ($name === StepRegistry::MEMBER_CONFIG_NOTIFICATION_FAMILY) {
                continue; // expanded above, and pinned by NotificationSettingDispositionsTest
            }
            $this->assertContains($name, $known);
        }
    }

    /**
     * A name a step reads but the set omits is reported as unrecognised while migrated, so the
     * literals MemberUpgrade and GroupUpgrade pin in their subqueries are read back out of the
     * emitted SQL rather than restated as a second list.
     */
    public function test_the_names_the_subquery_steps_actually_read_are_recognised(): void
    {
        $sets = [
            [new MemberUpgrade, 'member_config', StepRegistry::knownMemberConfigNames()],
            [new GroupUpgrade, 'community_config', StepRegistry::knownCommunityConfigNames()],
        ];

        foreach ($sets as [$step, $table, $known]) {
            $class = $step::class;
            $read = self::configNamesReadBy($step, $table);

            $this->assertNotEmpty($read, "{$class} reads no config name — the SQL shape changed");
            foreach ($read as $name) {
                $this->assertContains($name, $known, "{$class} reads `{$name}` but the preflight would call it unrecognised");
            }
        }
    }

    /**
     * The `name = '…'` literals a step's column expressions read from one KV table. Each
     * `{{src:table}}` token opens a subquery, so the names between one token and the next belong to
     * it — MemberUpgrade also reads sns_config by name, and those are a different table's keys.
     *
     * @return list<string>
     */
    private static function configNamesReadBy(UpgradeStep $step, string $table): array
    {
        $names = [];
        foreach ($step->columns() as $column) {
            foreach (explode('{{src:', $column->expr ?? '') as $segment) {
                if (! str_starts_with($segment, $table.'}}')) {
                    continue;
                }
                preg_match_all("/`name` = '([^']*)'/", $segment, $matches);
                $names = [...$names, ...$matches[1]];
            }
        }

        return array_values(array_unique($names));
    }

    public function test_the_sets_are_never_empty(): void
    {
        // The preflight expands them into a `name NOT IN (…)` list, which an empty set makes invalid.
        $this->assertNotEmpty(StepRegistry::knownMemberConfigNames());
        $this->assertNotEmpty(StepRegistry::knownCommunityConfigNames());
    }

    public function test_community_config_names_are_the_documented_dispositions(): void
    {
        $this->assertSame(
            array_keys(StepRegistry::communityConfigDispositions()),
            StepRegistry::knownCommunityConfigNames(),
        );

        foreach (StepRegistry::knownCommunityConfigNames() as $name) {
            $this->assertStringNotContainsString('*', $name, "`{$name}` is a family, not a name the source can hold");
        }
    }
}
