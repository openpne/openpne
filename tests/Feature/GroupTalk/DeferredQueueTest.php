<?php

namespace Tests\Feature\GroupTalk;

use App\Models\GroupMessage;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class DeferredQueueTest extends TalkTestCase
{
    public function test_a_post_answers_before_its_broadcast_runs_and_the_recipients_are_still_notified(): void
    {
        config(['queue.default' => 'deferred']);
        DB::table('sns_settings')->insert(['key' => SnsSettingKey::GroupTalkNotifyDefault->value, 'value' => 'all']);
        $group = $this->group();
        $author = $this->memberOf($group);
        $reader = $this->memberOf($group);

        $rowsWhenAnswering = null;
        Event::listen(RequestHandled::class, function () use (&$rowsWhenAnswering): void {
            $rowsWhenAnswering ??= DB::table('notifications')->count();
        });

        $response = $this->actingAs($author)->postJson("/groups/{$group->getKey()}/talk", ['body' => 'hello']);

        $response->assertCreated();
        $response->assertHeader('Content-Length');
        // RequestHandled fires once the response exists and before terminate(): no notification yet.
        $this->assertSame(0, $rowsWhenAnswering);
        $this->assertSame(1, GroupMessage::query()->count());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $reader->getKey()]);
    }
}
