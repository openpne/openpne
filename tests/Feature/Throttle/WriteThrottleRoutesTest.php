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
            'communityTopic.store' => ['communityTopic.store', 'throttle:posting'],
            'communityTopic.update' => ['communityTopic.update', 'throttle:posting'],
            'communityTopic.comment.store' => ['communityTopic.comment.store', 'throttle:posting'],
            'communityEvent.store' => ['communityEvent.store', 'throttle:posting'],
            'communityEvent.update' => ['communityEvent.update', 'throttle:posting'],
            'communityEvent.comment.store' => ['communityEvent.comment.store', 'throttle:posting'],
            'timeline.store' => ['timeline.store', 'throttle:posting'],
            'timeline.reply.store' => ['timeline.reply.store', 'throttle:posting'],
            'message.compose.store' => ['message.compose.store', 'throttle:message-send'],
            'message.draft.update' => ['message.draft.update', 'throttle:message-send'],
            'friend.link' => ['friend.link', 'throttle:friend-request'],
            'friend.accept' => ['friend.accept', 'throttle:friend-request'],
            'community.join' => ['community.join', 'throttle:community-join'],
            'community.members.approve' => ['community.members.approve', 'throttle:community-join'],
            'community.members.decline' => ['community.members.decline', 'throttle:community-join'],
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
