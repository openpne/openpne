<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Support\PreferenceKey;
use App\Upgrade\StepRegistry;
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

    public function test_member_config_names_cover_every_registered_notification_key(): void
    {
        $known = StepRegistry::knownMemberConfigNames();

        foreach (NotificationKind::cases() as $kind) {
            foreach (NotificationChannel::cases() as $channel) {
                $this->assertContains($kind->op3ConfigName($channel), $known);
            }
        }
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
