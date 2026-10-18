<?php

namespace Tests\Feature\GroupTalk;

use App\Models\GroupMessage;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\FakesWebPushTransport;

class DeferredQueueTest extends TalkTestCase
{
    use FakesWebPushTransport;

    public function test_a_post_answers_before_its_broadcast_and_push_run_and_both_still_happen(): void
    {
        config(['queue.default' => 'deferred']);
        $this->configureVapid();
        $transport = $this->fakeWebPushTransport();
        DB::table('sns_settings')->insert(['key' => SnsSettingKey::GroupTalkNotifyDefault->value, 'value' => 'all']);
        $group = $this->group();
        $author = $this->memberOf($group);
        $reader = $this->memberOf($group);
        $reader->updatePushSubscription('https://push.example.com/reader', str_repeat('k', 87), str_repeat('a', 22), 'aes128gcm');

        $whenAnswering = null;
        Event::listen(RequestHandled::class, function () use (&$whenAnswering, $transport): void {
            $whenAnswering ??= ['rows' => DB::table('notifications')->count(), 'pushes' => count($transport->sent)];
        });

        $response = $this->actingAs($author)->postJson("/groups/{$group->getKey()}/talk", ['body' => 'hello']);

        $response->assertCreated();
        $response->assertHeader('Content-Length');
        // RequestHandled fires once the response exists and before terminate(): nothing sent yet.
        $this->assertSame(['rows' => 0, 'pushes' => 0], $whenAnswering);
        $this->assertSame(1, GroupMessage::query()->count());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $reader->getKey()]);
        // The push is queued from inside the deferred broadcast; the deferred run picks it up too.
        $this->assertCount(1, $this->pushesTo('https://push.example.com/reader'));
    }
}
