<?php

declare(strict_types=1);

namespace Tests\Feature\Group\Listeners;

use App\Features\Group\Events\AdminTransferRequested;
use App\Listeners\Group\NotifyAdminTransferRequested;
use App\Models\Group;
use App\Models\Member;
use App\Notifications\Group\AdminTransferRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotifyAdminTransferRequestedTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_only_the_nominee_on_the_database_channel(): void
    {
        Notification::fake();
        [$requester, $nominee, $other] = Member::factory()->count(3)->create()->all();
        $group = Group::factory()->create();

        app(NotifyAdminTransferRequested::class)->handle(new AdminTransferRequested($group, $requester, $nominee));

        Notification::assertSentTo(
            $nominee,
            AdminTransferRequestedNotification::class,
            fn (AdminTransferRequestedNotification $n, array $channels) => $channels === ['database']
                && $n->group->is($group)
                && $n->requester->is($requester),
        );
        Notification::assertNotSentTo([$requester, $other], AdminTransferRequestedNotification::class);
    }

    public function test_stores_a_database_row_with_the_kind_and_ids(): void
    {
        [$requester, $nominee] = Member::factory()->count(2)->create()->all();
        $group = Group::factory()->create();

        app(NotifyAdminTransferRequested::class)->handle(new AdminTransferRequested($group, $requester, $nominee));

        $row = DatabaseNotification::query()->where('notifiable_id', $nominee->getKey())->sole();
        $this->assertSame('group_admin_transfer_requested', $row->data['kind']);
        $this->assertSame($group->getKey(), $row->data['group_id']);
        $this->assertSame($requester->getKey(), $row->data['requester_id']);
    }
}
