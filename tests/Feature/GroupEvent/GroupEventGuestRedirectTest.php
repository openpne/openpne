<?php

namespace Tests\Feature\GroupEvent;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The bounce is the `auth` middleware, which runs before the surface is chosen and before
 * route-model binding, so one surface and unbacked ids stand for every arm.
 */
class GroupEventGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function memberOnlyRoutes(): array
    {
        return [
            'list' => ['get', '/groups/1/events'],
            'new' => ['get', '/groups/1/events/new'],
            'store' => ['post', '/groups/1/events'],
            'show' => ['get', '/events/1'],
            'member list' => ['get', '/events/1/members'],
            'delete' => ['post', '/events/1/delete'],
            'comment store' => ['post', '/events/1/comments'],
            'comment delete' => ['post', '/events/comments/1/delete'],
        ];
    }

    #[DataProvider('memberOnlyRoutes')]
    public function test_a_guest_is_sent_to_login(string $method, string $url): void
    {
        $this->{$method}($url)->assertRedirect('/login');
    }
}
