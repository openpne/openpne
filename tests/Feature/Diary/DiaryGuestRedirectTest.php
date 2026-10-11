<?php

namespace Tests\Feature\Diary;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * These rows are guest-reachable and bounce from the controller or the route's `missing()` closure
 * rather than `auth`, which send a guest with an unbacked id to the login screen rather than a 404.
 */
class DiaryGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

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
