<?php

namespace Tests\Feature\Throttle;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins the named limiter on the routes listed below, so a route edit that drops one of these
 * throttles fails here rather than silently in production.
 */
class WriteThrottleRoutesTest extends TestCase
{
    /** The limiters AppServiceProvider::configureRateLimiting() builds with writeLimiter(). */
    private const LIMITERS = ['posting', 'preview', 'mention-search', 'direct-message-send', 'friend-request', 'group-join', 'reaction'];

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
            'group.talk.store' => ['group.talk.store', 'throttle:posting'],
            'compose.preview' => ['compose.preview', 'throttle:preview'],
            'timeline.mention_candidates' => ['timeline.mention_candidates', 'throttle:mention-search'],
            'group.talk.mention_candidates' => ['group.talk.mention_candidates', 'throttle:mention-search'],
            'message.chat.recipients' => ['message.chat.recipients', 'throttle:mention-search'],
            'message.compose.store' => ['message.compose.store', 'throttle:direct-message-send'],
            'message.draft.update' => ['message.draft.update', 'throttle:direct-message-send'],
            'message.chat.store' => ['message.chat.store', 'throttle:direct-message-send'],
            'friend.link' => ['friend.link', 'throttle:friend-request'],
            'friend.accept' => ['friend.accept', 'throttle:friend-request'],
            'notifications.center.friendAccept' => ['notifications.center.friendAccept', 'throttle:friend-request'],
            'group.join' => ['group.join', 'throttle:group-join'],
            'group.members.approve' => ['group.members.approve', 'throttle:group-join'],
            'group.members.decline' => ['group.members.decline', 'throttle:group-join'],
            'member.config.ai.groups.join' => ['member.config.ai.groups.join', 'throttle:group-join'],
            'group.talk.reactions.store' => ['group.talk.reactions.store', 'throttle:reaction'],
            'group.talk.reactions.delete' => ['group.talk.reactions.delete', 'throttle:reaction'],
        ];
    }

    #[DataProvider('throttledRoutes')]
    public function test_write_route_carries_its_named_throttle(string $name, string $throttle): void
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertInstanceOf(RoutingRoute::class, $route, "route [{$name}] is not registered");

        $this->assertContains($throttle, $route->gatherMiddleware(), "route [{$name}] lost [{$throttle}]");
    }

    /** The list above is an allowlist, so a new throttled route has to declare itself here. */
    public function test_every_route_carrying_a_write_limiter_is_listed(): void
    {
        $limiters = array_map(static fn (string $limiter): string => "throttle:{$limiter}", self::LIMITERS);
        $carrying = [];
        foreach (Route::getRoutes() as $route) {
            if (array_intersect($route->gatherMiddleware(), $limiters) !== []) {
                $carrying[] = $route->getName() ?? $route->uri();
            }
        }
        sort($carrying);
        $listed = array_keys(self::throttledRoutes());
        sort($listed);

        $this->assertSame($listed, $carrying);
    }
}
