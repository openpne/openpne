<?php

namespace App\Features\DirectMessage;

use App\Features\DirectMessage\Queries\ConversationMessages;
use App\Features\DirectMessage\Serializers\ConversationMessageSerializer;
use App\Features\DirectMessage\Serializers\DirectMessageSerializer;
use App\Http\Controllers\Controller;
use App\Models\DirectMessage;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 * over JSON — "load older" walks back by keyset and a poll asks what has arrived since.
 */
class ConversationController extends Controller
{
    public function show(Request $request, Member $member, ConversationMessages $query): InertiaResponse
    {
        return $this->conversation($request, $this->counterpart($member), $query);
    }

    /**
     * Everyone whose account is gone, as one conversation. The FKs are nullOnDelete, so a withdrawn
     * member leaves no id to key a conversation by, and a per-person room could not be addressed.
     */
    public function showWithdrawn(Request $request, ConversationMessages $query): InertiaResponse
    {
        return $this->conversation($request, null, $query);
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

    private function conversation(Request $request, ?Member $counterpart, ConversationMessages $query): InertiaResponse
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
        ]);
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
