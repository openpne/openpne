<?php

namespace App\Features\DirectMessage;

use App\Features\DirectMessage\Actions\MarkConversationRead;
use App\Features\DirectMessage\Exceptions\DirectMessageActionException;
use App\Features\DirectMessage\Queries\ConversationMessages;
use App\Features\DirectMessage\Queries\ConversationUnreadSnapshot;
use App\Features\DirectMessage\Serializers\ConversationMessageSerializer;
use App\Features\DirectMessage\Serializers\DirectMessageSerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\DirectMessage\MarkConversationReadRequest;
use App\Models\DirectMessage;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * One conversation with one member, read as chat: the stored mailbox rows composed back into the two
 * directions of a single thread (ConversationScope). Read-only — composing, marking read and the
 * conversation list are the mailbox's still.
 *
 * Renders Inertia directly rather than through SurfaceResolver: chat has no OpenPNE 3 counterpart, so
 * there is no Classic screen to be compatible with, as a group's talk takes the same shape.
 *
 * The page ships the newest slice, or the one a `?m=` deep link lands in; everything after it moves
 * over JSON — "load older" walks back by keyset and a poll asks what has arrived since. Composing is
 * the mailbox's still; marking read is the reader's own act and belongs to the screen doing it.
 */
class ConversationController extends Controller
{
    public function show(Request $request, Member $member, ConversationMessages $query, ConversationUnreadSnapshot $unread): InertiaResponse
    {
        return $this->conversation($request, $this->counterpart($member), $query, $unread);
    }

    /**
     * Everyone whose account is gone, as one conversation. The FKs are nullOnDelete, so a withdrawn
     * member leaves no id to key a conversation by, and a per-person room could not be addressed.
     */
    public function showWithdrawn(Request $request, ConversationMessages $query, ConversationUnreadSnapshot $unread): InertiaResponse
    {
        return $this->conversation($request, null, $query, $unread);
    }

    /**
     * "I have read this conversation as far as this message." Fire-and-forget from the reader's
     * side: it carries no body back, and the shell's own refresh is what moves the badge.
     */
    public function read(MarkConversationReadRequest $request, Member $member, MarkConversationRead $action): Response
    {
        return $this->markRead($request, $this->counterpart($member), $action);
    }

    public function readWithdrawn(MarkConversationReadRequest $request, MarkConversationRead $action): Response
    {
        return $this->markRead($request, null, $action);
    }

    /**
     * One page either side of a cursor the client was handed: `after` for the poll, `before` for
     * "load older", `context` for the page a position sits in. A cursor that does not parse is simply
     * no cursor — pagination is a position, not a permission, and the conversation's own predicate
     * already decided which rows exist for this reader.
     */
    public function messages(Request $request, Member $member, ConversationMessages $query): JsonResponse
    {
        return $this->page($request, $this->counterpart($member), $query);
    }

    public function withdrawnMessages(Request $request, ConversationMessages $query): JsonResponse
    {
        return $this->page($request, null, $query);
    }

    /** A conversation is with someone else; there is no room to be in with yourself (OpenPNE 3 404s a self-addressed message). */
    private function counterpart(Member $member): Member
    {
        abort_if($this->viewer()->is($member), 404);

        return $member;
    }

    private function conversation(Request $request, ?Member $counterpart, ConversationMessages $query, ConversationUnreadSnapshot $unread): InertiaResponse
    {
        $viewer = $this->viewer();
        $anchor = $this->anchor($viewer, $counterpart, $request->query('m'));

        return Inertia::render('message/conversation/index', [
            'counterpart' => $counterpart === null
                ? null
                : DirectMessageSerializer::memberRef($counterpart->loadMissing('avatar.file')),
            'page' => ConversationMessageSerializer::page(
                $anchor === null
                    ? $query->latest($viewer, $counterpart)
                    : $query->around($viewer, $counterpart, ConversationCursor::of($anchor)),
                $viewer,
                $counterpart,
            ),
            'anchor' => $anchor === null ? null : (int) $anchor->getKey(),
            // Where the reader stands in this conversation, independent of the slice `?m=` opened:
            // a link names a message, the boundary names what has not been read.
            'unreadSnapshot' => ConversationMessageSerializer::unreadSnapshot($unread($viewer, $counterpart)),
        ]);
    }

    private function markRead(MarkConversationReadRequest $request, ?Member $counterpart, MarkConversationRead $action): Response
    {
        try {
            $action($this->viewer(), $counterpart, (int) $request->validated('messageId'));
        } catch (DirectMessageActionException) {
            // A message trashed from the mailbox between rendering and this call is an ordinary
            // race, and so is a stale id from another conversation; neither is worth its own answer.
            abort(404);
        }

        return response()->noContent();
    }

    private function page(Request $request, ?Member $counterpart, ConversationMessages $query): JsonResponse
    {
        $viewer = $this->viewer();

        $context = ConversationCursor::tryParse($request->query('context'));
        $after = ConversationCursor::tryParse($request->query('after'));
        $before = ConversationCursor::tryParse($request->query('before'));

        $page = match (true) {
            $context !== null => $query->around($viewer, $counterpart, $context),
            $after !== null => $query->after($viewer, $counterpart, $after),
            $before !== null => $query->before($viewer, $counterpart, $before),
            default => $query->latest($viewer, $counterpart),
        };

        return response()->json(ConversationMessageSerializer::page($page, $viewer, $counterpart));
    }

    /**
     * The message `?m=` asked to open on, or null for the ordinary newest page.
     *
     * Best-effort by contract, and resolved through the conversation's own predicate: a link naming a
     * message from another conversation, a draft, one the viewer's own side has trashed or purged, or
     * nothing that parses opens the newest page instead. (The counterpart's trash never hides a row
     * here — visibility is per-side.) A stale link is a link to a conversation that has moved on, and
     * arriving in it beats being refused.
     */
    private function anchor(Member $viewer, ?Member $counterpart, mixed $id): ?DirectMessage
    {
        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        return ConversationScope::apply(DirectMessage::query(), $viewer, $counterpart)->find((int) $id);
    }
}
