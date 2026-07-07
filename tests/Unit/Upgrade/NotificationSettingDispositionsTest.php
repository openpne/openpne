<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use App\Upgrade\StepRegistry;
use App\Upgrade\Steps\CommunityMemberUpgrade;
use PHPUnit\Framework\TestCase;

/**
 * Pins the notification-related dispositions: the is_send_* member_config keys migrate to the
 * settings store, and community_member.is_receive_mail_pc is a documented drop (superseded by
 * the member-level catalog, not deferred work). Rewording either is a deliberate act.
 */
class NotificationSettingDispositionsTest extends TestCase
{
    public function test_member_config_notification_keys_point_at_the_settings_upgrade(): void
    {
        $disposition = StepRegistry::memberConfigDispositions()['is_send_*_mail / is_send_*_web'];

        $this->assertStringContainsString('member_notification_settings', $disposition);
        $this->assertStringContainsString('MemberNotificationSettingUpgrade', $disposition);
    }

    public function test_is_receive_mail_pc_is_a_documented_drop(): void
    {
        $gap = (new CommunityMemberUpgrade)->gaps()['is_receive_mail_pc'];

        $this->assertStringStartsWith('Dropped:', $gap);
        $this->assertStringContainsString('member_notification_settings', $gap);
        $this->assertStringContainsString('per-community granularity is not carried', $gap);
    }
}
