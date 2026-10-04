<?php

namespace Tests\Feature\Group;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The bounce is the `auth` middleware, which runs before the surface is chosen and before
 * route-model binding, so one surface and unbacked ids stand for every arm.
 */
class GroupGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function memberOnlyRoutes(): array
    {
        return [
            'search' => ['get', '/groups'],
            'mine' => ['get', '/groups/mine'],
            'show' => ['get', '/groups/1'],
            'members' => ['get', '/groups/1/members'],
            'join' => ['post', '/groups/1/join'],
            'edit form' => ['get', '/groups/edit'],
            'save' => ['post', '/groups/edit'],
            'delete' => ['post', '/groups/1/delete'],
            'pending members' => ['get', '/groups/1/members/pending'],
            'manage members' => ['get', '/groups/1/members/manage'],
            'appoint' => ['post', '/groups/1/members/appoint'],
            'drop' => ['post', '/groups/1/members/drop'],
        ];
    }

    #[DataProvider('memberOnlyRoutes')]
    public function test_a_guest_is_sent_to_login(string $method, string $url): void
    {
        $this->{$method}($url)->assertRedirect('/login');
    }
}
