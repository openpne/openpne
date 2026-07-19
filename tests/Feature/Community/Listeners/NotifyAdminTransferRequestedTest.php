<?php

declare(strict_types=1);

namespace Tests\Feature\Community\Listeners;

use App\Features\Community\Events\AdminTransferRequested;
use App\Listeners\Community\NotifyAdminTransferRequested;
use App\Models\Community;
use App\Models\Member;
use App\Notifications\Community\AdminTransferRequestedNotification;
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
        $community = Community::factory()->create();

        app(NotifyAdminTransferRequested::class)->handle(new AdminTransferRequested($community, $requester, $nominee));

        Notification::assertSentTo(
            $nominee,
            AdminTransferRequestedNotification::class,
            fn (AdminTransferRequestedNotification $n, array $channels) => $channels === ['database']
                && $n->community->is($community)
                && $n->requester->is($requester),
        );
        Notification::assertNotSentTo([$requester, $other], AdminTransferRequestedNotification::class);
    }

    public function test_stores_a_database_row_with_the_kind_and_ids(): void
    {
        [$requester, $nominee] = Member::factory()->count(2)->create()->all();
        $community = Community::factory()->create();

        app(NotifyAdminTransferRequested::class)->handle(new AdminTransferRequested($community, $requester, $nominee));

        $row = DatabaseNotification::query()->where('notifiable_id', $nominee->getKey())->sole();
        $this->assertSame('community_admin_transfer_requested', $row->data['kind']);
        $this->assertSame($community->getKey(), $row->data['community_id']);
        $this->assertSame($requester->getKey(), $row->data['requester_id']);
    }
}
