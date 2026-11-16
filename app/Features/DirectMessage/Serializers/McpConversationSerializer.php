<?php

declare(strict_types=1);

namespace App\Features\DirectMessage\Serializers;

use App\Features\DirectMessage\ConversationCursor;
use App\Features\DirectMessage\ConversationPage;
use App\Features\DirectMessage\ConversationSummary;
use App\Features\GroupTalk\Serializers\McpTalkSerializer;
use App\Files\PostAttachments;
use App\Models\DirectMessage;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The bearer client's shape, separate from the Modern surface's ({@see ConversationMessageSerializer})
 * for the reason talk's is ({@see McpTalkSerializer}): those carry
 * session-guarded file URLs, so attachments are reported as counts here. A conversation is addressed
 * by its counterpart's member id; the withdrawn bucket names nobody and is listed with a null one.
 */
class McpConversationSerializer
{
    /**
     * @return array{memberId: int|null, name: string|null, isAi: bool, unread: int, lastMessageAt: string, preview: string}
     */
    public static function conversation(ConversationSummary $row): array
    {
        $latest = $row->latest;

        return [
            'memberId' => $row->counterpart?->getKey(),
            'name' => $row->counterpart?->name,
            'isAi' => (bool) $row->counterpart?->isAiAccount(),
            'unread' => $row->unread,
            'lastMessageAt' => CarbonImmutable::instance($latest->created_at)->toIso8601String(),
            'preview' => ConversationListSerializer::preview($latest),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, ConversationSummary>  $paginator
     * @return array{conversations: list<array<string, mixed>>, page: int, lastPage: int, total: int}
     */
    public static function conversations(LengthAwarePaginator $paginator): array
    {
        return [
            'conversations' => array_map([self::class, 'conversation'], $paginator->items()),
            'page' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * @return array{id: int, body: string, subject: string|null, authorId: int|null, authorName: string|null, authorIsAi: bool, isOwn: bool, read: bool|null, createdAt: string, cursor: string, hasImages: bool, imageCount: int, fileCount: int}
     */
    public static function message(DirectMessage $message, Member $viewer, Member $counterpart): array
    {
        $attachments = PostAttachments::partition($message->files);
        $images = $attachments['images']->count();

        return [
            'id' => (int) $message->getKey(),
            // A legacy row may hold a null body (subject only), which is a message all the same.
            'body' => (string) $message->body,
            'subject' => $message->subject === null || $message->subject === '' ? null : (string) $message->subject,
            'authorId' => $message->sender?->getKey(),
            'authorName' => $message->sender?->name,
            'authorIsAi' => (bool) $message->sender?->isAiAccount(),
            'isOwn' => (int) $message->sender_id === (int) $viewer->getKey(),
            'read' => ConversationMessageSerializer::read($message, (int) $viewer->getKey(), $counterpart),
            'createdAt' => CarbonImmutable::instance($message->created_at)->toIso8601String(),
            'cursor' => (string) ConversationCursor::of($message),
            'hasImages' => $images > 0,
            'imageCount' => $images,
            'fileCount' => $attachments['files']->count(),
        ];
    }

    /**
     * @return array{messages: list<array<string, mixed>>, hasOlder: bool, hasNewer: bool, previousCursor: string|null, nextCursor: string|null}
     */
    public static function page(ConversationPage $page, Member $viewer, Member $counterpart): array
    {
        $messages = $page->messages
            ->map(fn (DirectMessage $message): array => self::message($message, $viewer, $counterpart))
            ->values()
            ->all();

        return [
            'messages' => $messages,
            'hasOlder' => $page->hasOlder,
            'hasNewer' => $page->hasNewer,
            'previousCursor' => $messages === [] ? null : $messages[0]['cursor'],
            'nextCursor' => $messages === [] ? null : $messages[count($messages) - 1]['cursor'],
        ];
    }
}
