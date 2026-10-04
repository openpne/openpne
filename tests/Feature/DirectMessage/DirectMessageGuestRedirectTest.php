<?php

namespace Tests\Feature\DirectMessage;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The bounce is the `auth` middleware, which runs before the surface is chosen and before
 * route-model binding, so one surface and unbacked ids stand for every arm.
 */
class DirectMessageGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function memberOnlyRoutes(): array
    {
        return [
            'index' => ['get', '/message'],
            'inbox' => ['get', '/message/receiveList'],
            'sent' => ['get', '/message/sendList'],
            'drafts' => ['get', '/message/draftList'],
            'trash' => ['get', '/message/dustList'],
            'received message' => ['get', '/message/read/1'],
            'compose' => ['get', '/message/sendToFriend?id=1'],
            'reply' => ['get', '/message/reply/1'],
            'send' => ['post', '/message/sendToFriend'],
            'draft edit' => ['get', '/message/edit/1'],
            'trash received' => ['post', '/message/deleteReceiveMessage/1'],
            'restore' => ['post', '/message/restore/1'],
            'purge' => ['post', '/message/deleteComplete/1'],
            'bulk' => ['post', '/message/bulk'],
            'conversations' => ['get', '/messages'],
            'recipients' => ['get', '/messages/recipients'],
            'conversation' => ['get', '/messages/1'],
            'conversation messages' => ['get', '/messages/1/messages'],
            'conversation read' => ['post', '/messages/1/read'],
            'conversation delete' => ['post', '/messages/1/delete'],
            'withdrawn' => ['get', '/messages/withdrawn'],
            'withdrawn messages' => ['get', '/messages/withdrawn/messages'],
            'withdrawn read' => ['post', '/messages/withdrawn/read'],
            'withdrawn delete' => ['post', '/messages/withdrawn/delete'],
        ];
    }

    #[DataProvider('memberOnlyRoutes')]
    public function test_a_guest_is_sent_to_login(string $method, string $url): void
    {
        $this->{$method}($url)->assertRedirect('/login');
    }
}
