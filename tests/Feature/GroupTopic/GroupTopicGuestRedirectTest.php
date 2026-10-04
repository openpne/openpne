<?php

namespace Tests\Feature\GroupTopic;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The bounce is the `auth` middleware, which runs before the surface is chosen and before
 * route-model binding, so one surface and unbacked ids stand for every arm.
 */
class GroupTopicGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function memberOnlyRoutes(): array
    {
        return [
            'board' => ['get', '/groups/1/topics'],
            'new' => ['get', '/groups/1/topics/new'],
            'store' => ['post', '/groups/1/topics'],
            'show' => ['get', '/topics/1'],
            'edit' => ['get', '/topics/1/edit'],
            'delete' => ['post', '/topics/1/delete'],
            'comment store' => ['post', '/topics/1/comments'],
            'comment delete' => ['post', '/topics/comments/1/delete'],
        ];
    }

    #[DataProvider('memberOnlyRoutes')]
    public function test_a_guest_is_sent_to_login(string $method, string $url): void
    {
        $this->{$method}($url)->assertRedirect('/login');
    }
}
