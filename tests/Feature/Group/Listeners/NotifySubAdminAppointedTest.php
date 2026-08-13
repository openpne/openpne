<?php

declare(strict_types=1);

namespace Tests\Feature\Group\Listeners;

use App\Features\Group\Events\SubAdminAppointed;
use App\Listeners\Group\NotifySubAdminAppointed;
use App\Models\Group;
use App\Models\Member;
use App\Notifications\Group\SubAdminAppointedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotifySubAdminAppointedTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_only_the_appointee_on_the_database_channel(): void
    {
        Notification::fake();
        [$appointer, $appointee, $other] = Member::factory()->count(3)->create()->all();
        $group = Group::factory()->create();

        app(NotifySubAdminAppointed::class)->handle(new SubAdminAppointed($group, $appointer, $appointee));

        Notification::assertSentTo(
            $appointee,
            SubAdminAppointedNotification::class,
            fn (SubAdminAppointedNotification $n, array $channels) => $channels === ['database']
                && $n->group->is($group)
                && $n->appointer->is($appointer),
        );
        Notification::assertNotSentTo([$appointer, $other], SubAdminAppointedNotification::class);
    }

    public function test_stores_a_database_row_with_the_kind_and_ids(): void
    {
        [$appointer, $appointee] = Member::factory()->count(2)->create()->all();
        $group = Group::factory()->create();

        app(NotifySubAdminAppointed::class)->handle(new SubAdminAppointed($group, $appointer, $appointee));

        $row = DatabaseNotification::query()->where('notifiable_id', $appointee->getKey())->sole();
        $this->assertSame('group_sub_admin_appointed', $row->data['kind']);
        $this->assertSame($group->getKey(), $row->data['group_id']);
        $this->assertSame($appointer->getKey(), $row->data['appointer_id']);
    }
}
