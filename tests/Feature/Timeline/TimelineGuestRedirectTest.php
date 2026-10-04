<?php

namespace Tests\Feature\Timeline;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The bounce is the `auth` middleware, which runs before the surface is chosen and before
 * route-model binding, so one surface and unbacked ids stand for every arm.
 */
class TimelineGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function memberOnlyRoutes(): array
    {
        return [
            'home feed' => ['get', '/timeline'],
            'member timeline' => ['get', '/member/1/timeline'],
            'post' => ['get', '/timeline/1'],
            'new' => ['get', '/timeline/new'],
            'create' => ['post', '/timeline/create'],
            'reply' => ['post', '/timeline/1/reply'],
            'tag' => ['get', '/timeline/tag/op4'],
        ];
    }

    #[DataProvider('memberOnlyRoutes')]
    public function test_a_guest_is_sent_to_login(string $method, string $url): void
    {
        $this->{$method}($url)->assertRedirect('/login');
    }
}
