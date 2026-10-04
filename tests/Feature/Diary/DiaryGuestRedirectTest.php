<?php

namespace Tests\Feature\Diary;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The bounce is the `auth` middleware, which runs before the surface is chosen and before
 * route-model binding, so one surface and unbacked ids stand for every arm. The entry and the
 * month archive are guest-reachable and bounce from the controller instead: a missing id gets
 * the same answer as an author who publishes nothing web-public, so there is no existence oracle.
 */
class DiaryGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function memberOnlyRoutes(): array
    {
        return [
            'own archive' => ['get', '/diary/listMember'],
            'month archive' => ['get', '/diary/listMember/1/2026/3'],
            'friend feed' => ['get', '/diary/listFriend'],
            'new' => ['get', '/diary/new'],
            'create' => ['post', '/diary/create'],
            'edit' => ['get', '/diary/edit/1'],
            'update' => ['post', '/diary/update/1'],
            'delete confirm' => ['get', '/diary/deleteConfirm/1'],
            'delete' => ['post', '/diary/delete/1'],
            'entry' => ['get', '/diary/1'],
            'comment create' => ['post', '/diary/1/comment/create'],
            'comment delete confirm' => ['get', '/diary/comment/deleteConfirm/1'],
            'comment delete' => ['post', '/diary/comment/delete/1'],
            'comment history' => ['get', '/diary/comment/history'],
        ];
    }

    #[DataProvider('memberOnlyRoutes')]
    public function test_a_guest_is_sent_to_login(string $method, string $url): void
    {
        $this->{$method}($url)->assertRedirect('/login');
    }
}
