<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Notifications\Concerns\GatedByFeature;
use App\Notifications\FeatureNotification;
use App\Notifications\FeatureNotificationMap;
use App\Support\Feature;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

/**
 * Pins the notification side of the feature toggles (docs/internals/feature-toggles.md): a
 * notification added under a feature's namespace later must declare its unit and carry the send
 * gate, or it would keep arriving after an administrator switched the unit off — and its rows would
 * keep showing in the feed, since FeatureNotificationMap is what the display filter reads.
 */
class FeatureNotificationCoverageTest extends TestCase
{
    /** The notification namespaces a feature unit owns, and the unit each one belongs to. */
    private const OWNED = [
        'Diary' => Feature::Diary,
        'DirectMessage' => Feature::DirectMessage,
        'Friend' => Feature::Friend,
        'Group' => Feature::Group,
        'GroupTopic' => Feature::GroupTopic,
        'GroupEvent' => Feature::GroupEvent,
        'Timeline' => Feature::Timeline,
    ];

    /** @return list<class-string<Notification>> every notification class under app/Notifications/$dir */
    private function notificationsIn(string $dir): array
    {
        $classes = [];
        foreach (glob(app_path("Notifications/{$dir}").'/*.php') ?: [] as $file) {
            $class = 'App\\Notifications\\'.$dir.'\\'.basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, Notification::class)) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    public function test_every_feature_owned_notification_declares_its_unit_and_carries_the_gate(): void
    {
        $scanned = [];
        foreach (self::OWNED as $dir => $feature) {
            foreach ($this->notificationsIn($dir) as $class) {
                $scanned[] = $class;

                $this->assertTrue(
                    is_a($class, FeatureNotification::class, true),
                    "{$class} must implement ".FeatureNotification::class.' so its unit is declared once, for both the send gate and the feed filter.',
                );
                $this->assertContains(
                    GatedByFeature::class,
                    class_uses_recursive($class),
                    "{$class} must use ".GatedByFeature::class.': declaring the unit without shouldSend() leaves the notification ungated (via() is re-read too early to help).',
                );
                $this->assertSame(
                    $feature,
                    $class::feature(),
                    "{$class} lives under App\\Notifications\\{$dir} but claims a different unit.",
                );
            }
        }

        $this->assertNotEmpty($scanned, 'Found no feature-owned notifications to scan — the walk is broken.');
    }

    public function test_the_display_map_lists_exactly_the_feature_owned_notifications(): void
    {
        $expected = [];
        foreach (array_keys(self::OWNED) as $dir) {
            $expected = [...$expected, ...$this->notificationsIn($dir)];
        }
        sort($expected);

        $listed = FeatureNotificationMap::CLASSES;
        sort($listed);

        $this->assertSame($expected, $listed, 'FeatureNotificationMap::CLASSES must list every feature-owned notification and nothing else — it is what hides a switched-off unit\'s rows from the feed.');
        $this->assertSame($listed, array_unique($listed), 'A duplicate entry would filter the same type twice.');
    }

    public function test_account_notifications_are_not_feature_owned(): void
    {
        // Password resets, registration mail and the security alerts must keep arriving whatever an
        // administrator switched off; they belong to no unit and stay out of the registry.
        foreach (['Auth', 'Member'] as $dir) {
            foreach ($this->notificationsIn($dir) as $class) {
                $this->assertFalse(
                    is_a($class, FeatureNotification::class, true),
                    "{$class} is an account notification and must not be gated by a feature unit.",
                );
            }
        }
    }
}
