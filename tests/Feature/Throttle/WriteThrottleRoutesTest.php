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
    private const LIMITERS = ['posting', 'preview', 'mention-search', 'direct-message-send', 'friend-request', 'group-join', 'reaction', 'reaction-read'];

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
            'timeline.reactions.store' => ['timeline.reactions.store', 'throttle:reaction'],
            'timeline.reactions.delete' => ['timeline.reactions.delete', 'throttle:reaction'],
            'diary.reactions.store' => ['diary.reactions.store', 'throttle:reaction'],
            'diary.reactions.delete' => ['diary.reactions.delete', 'throttle:reaction'],
            'diary.comment.reactions.store' => ['diary.comment.reactions.store', 'throttle:reaction'],
            'diary.comment.reactions.delete' => ['diary.comment.reactions.delete', 'throttle:reaction'],
            'group.topics.reactions.store' => ['group.topics.reactions.store', 'throttle:reaction'],
            'group.topics.reactions.delete' => ['group.topics.reactions.delete', 'throttle:reaction'],
            'group.topics.comment.reactions.store' => ['group.topics.comment.reactions.store', 'throttle:reaction'],
            'group.topics.comment.reactions.delete' => ['group.topics.comment.reactions.delete', 'throttle:reaction'],
            'group.events.reactions.store' => ['group.events.reactions.store', 'throttle:reaction'],
            'group.events.reactions.delete' => ['group.events.reactions.delete', 'throttle:reaction'],
            'group.events.comment.reactions.store' => ['group.events.comment.reactions.store', 'throttle:reaction'],
            'group.events.comment.reactions.delete' => ['group.events.comment.reactions.delete', 'throttle:reaction'],
            'diary.reactions.index' => ['diary.reactions.index', 'throttle:reaction-read'],
            'diary.comment.reactions.index' => ['diary.comment.reactions.index', 'throttle:reaction-read'],
            'timeline.reactions.index' => ['timeline.reactions.index', 'throttle:reaction-read'],
            'group.topics.reactions.index' => ['group.topics.reactions.index', 'throttle:reaction-read'],
            'group.topics.comment.reactions.index' => ['group.topics.comment.reactions.index', 'throttle:reaction-read'],
            'group.talk.reactions.index' => ['group.talk.reactions.index', 'throttle:reaction-read'],
            'group.events.reactions.index' => ['group.events.reactions.index', 'throttle:reaction-read'],
            'group.events.comment.reactions.index' => ['group.events.comment.reactions.index', 'throttle:reaction-read'],
        ];
    }

    #[DataProvider('throttledRoutes')]
    public function test_route_carries_its_named_throttle(string $name, string $throttle): void
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertInstanceOf(RoutingRoute::class, $route, "route [{$name}] is not registered");

        $this->assertContains($throttle, $route->gatherMiddleware(), "route [{$name}] lost [{$throttle}]");
    }

    public function test_every_route_carrying_a_named_limiter_is_listed(): void
    {
        $limiters = array_map(static fn (string $limiter): string => "throttle:{$limiter}", self::LIMITERS);
        $carrying = [];
        foreach (Route::getRoutes() as $route) {
            if (array_intersect(array_filter($route->gatherMiddleware(), 'is_string'), $limiters) !== []) {
                $carrying[] = $route->getName() ?? $route->uri();
            }
        }
        sort($carrying);
        $listed = array_keys(self::throttledRoutes());
        sort($listed);

        $this->assertSame($listed, $carrying);
    }
}
