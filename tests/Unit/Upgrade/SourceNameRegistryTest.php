<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use App\Upgrade\SourceNameRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SourceNameRegistryTest extends TestCase
{
    public function test_it_keeps_names_and_families_per_table(): void
    {
        $registry = new SourceNameRegistry;
        $registry->recognise('member_config', 'webpay_customer_id', 'Migrated by the billing package.');
        $registry->recogniseFamily('member_config', 'community_message_unsubscribe_', 'Dropped by the billing package.');
        $registry->recognise('community_config', 'send_message_permission', 'Migrated by the messaging package.');

        $this->assertSame(['webpay_customer_id'], $registry->names('member_config'));
        $this->assertSame(['community_message_unsubscribe_'], $registry->prefixes('member_config'));
        $this->assertSame([
            'webpay_customer_id' => 'Migrated by the billing package.',
            'community_message_unsubscribe_*' => 'Dropped by the billing package.',
        ], $registry->dispositions('member_config'));
        $this->assertSame(['send_message_permission'], $registry->names('community_config'));
        $this->assertSame([], $registry->names('notification_mail'));
    }

    public function test_it_rejects_a_table_the_scan_does_not_cover(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SourceNameRegistry)->recognise('sns_config', 'anything', 'x');
    }

    public function test_it_rejects_an_empty_family_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SourceNameRegistry)->recogniseFamily('member_config', '', 'x');
    }
}
