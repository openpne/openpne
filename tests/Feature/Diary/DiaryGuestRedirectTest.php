<?php

namespace Tests\Feature\Diary;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The member-only rows bounce from the `auth` middleware, which runs before the surface is chosen and
 * before route-model binding, so one surface and unbacked ids stand for every arm. The archive and
 * entry rows are guest-reachable and bounce from the controller or the route's `missing()` closure
 * instead, which send a guest with an unbacked id to the login screen rather than a 404.
 */
class DiaryGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function memberOnlyRoutes(): array
    {
        return [
            'friend feed' => ['get', '/diary/listFriend'],
            'new' => ['get', '/diary/new'],
            'create' => ['post', '/diary/create'],
            'edit' => ['get', '/diary/edit/1'],
            'update' => ['post', '/diary/update/1'],
            'delete confirm' => ['get', '/diary/deleteConfirm/1'],
            'delete' => ['post', '/diary/delete/1'],
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

    /** @return array<string, array{string}> */
    public static function guestReachableRoutes(): array
    {
        return [
            'own archive' => ['/diary/listMember'],
            'month archive' => ['/diary/listMember/1/2026/3'],
            'entry' => ['/diary/1'],
        ];
    }

    #[DataProvider('guestReachableRoutes')]
    public function test_a_guest_reachable_page_sends_a_guest_with_an_unbacked_id_to_login(string $url): void
    {
        $this->get($url)->assertRedirect('/login');
    }
}
