<?php

namespace Tests\Feature\Throttle;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Drift guard: every content-posting and mail-triggering member write route keeps its named
 * write limiter. A route edit that drops the throttle must fail here, not silently in production.
 * The limiter definitions live in App\Providers\AppServiceProvider.
 */
class WriteThrottleRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function throttledRoutes(): array
    {
        // route name => expected throttle middleware
        return [
            'diary.store' => ['diary.store', 'throttle:posting'],
            'diary.update' => ['diary.update', 'throttle:posting'],
            'diary.comment.store' => ['diary.comment.store', 'throttle:posting'],
            'group.topics.store' => ['group.topics.store', 'throttle:posting'],
            'group.topics.update' => ['group.topics.update', 'throttle:posting'],
            'group.topics.comment.store' => ['group.topics.comment.store', 'throttle:posting'],
            'group.events.store' => ['group.events.store', 'throttle:posting'],
            'group.events.update' => ['group.events.update', 'throttle:posting'],
            'group.events.comment.store' => ['group.events.comment.store', 'throttle:posting'],
            'timeline.store' => ['timeline.store', 'throttle:posting'],
            'timeline.reply.store' => ['timeline.reply.store', 'throttle:posting'],
            'message.compose.store' => ['message.compose.store', 'throttle:direct-message-send'],
            'message.draft.update' => ['message.draft.update', 'throttle:direct-message-send'],
            'friend.link' => ['friend.link', 'throttle:friend-request'],
            'friend.accept' => ['friend.accept', 'throttle:friend-request'],
            'group.join' => ['group.join', 'throttle:group-join'],
            'group.members.approve' => ['group.members.approve', 'throttle:group-join'],
            'group.members.decline' => ['group.members.decline', 'throttle:group-join'],
        ];
    }

    #[DataProvider('throttledRoutes')]
    public function test_write_route_carries_its_named_throttle(string $name, string $throttle): void
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertInstanceOf(RoutingRoute::class, $route, "route [{$name}] is not registered");

        $this->assertContains($throttle, $route->gatherMiddleware(), "route [{$name}] lost [{$throttle}]");
    }
}
