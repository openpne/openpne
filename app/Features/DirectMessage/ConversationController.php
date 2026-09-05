<?php

namespace App\Features\DirectMessage;

use App\Features\DirectMessage\Actions\DeleteConversation;
use App\Features\DirectMessage\Actions\MarkConversationRead;
use App\Features\DirectMessage\Actions\SendDirectMessage;
use App\Features\DirectMessage\Exceptions\DirectMessageActionException;
use App\Features\DirectMessage\Queries\ConversationList;
use App\Features\DirectMessage\Queries\ConversationMessages;
use App\Features\DirectMessage\Queries\ConversationUnreadSnapshot;
use App\Features\DirectMessage\Queries\ListDirectMessages;
use App\Features\DirectMessage\Queries\RecipientCandidates;
use App\Features\DirectMessage\Serializers\ConversationListSerializer;
use App\Features\DirectMessage\Serializers\ConversationMessageSerializer;
use App\Features\DirectMessage\Serializers\DirectMessageSerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\DirectMessage\MarkConversationReadRequest;
use App\Http\Requests\DirectMessage\StoreChatMessageRequest;
use App\Models\DirectMessage;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Renders Inertia directly rather than through SurfaceResolver: chat has no OpenPNE 3 counterpart,
 * so there is no Classic screen to be compatible with
 * (`docs/internals/direct-messages.md`, "Modern reads the store as chat").
 */
class ConversationController extends Controller
{
    /** The drafts pager's own page parameter, so paging one list never moves the other. */
    private const DRAFT_PAGE = 'draft_page';

    public function index(ConversationList $conversations, ListDirectMessages $drafts): InertiaResponse
    {
        $viewer = $this->viewer();

        return Inertia::render('message/conversations/index', [
            'conversations' => ConversationListSerializer::paginator($conversations($viewer)),
            'drafts' => DirectMessageSerializer::paginator(
                $drafts($viewer, DirectMessageBox::Draft, pageName: self::DRAFT_PAGE),
            ),
        ]);
    }

    public function new(): InertiaResponse
    {
        return Inertia::render('message/new');
    }

    public function recipients(Request $request, RecipientCandidates $query): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $candidates = $query($this->viewer(), $request->string('q')->value());

        return response()->json([
            'candidates' => array_map([DirectMessageSerializer::class, 'memberRef'], $candidates->all()),
        ]);
    }

    public function show(Request $request, Member $member, ConversationMessages $query, ConversationUnreadSnapshot $unread): InertiaResponse
    {
        return $this->conversation($request, $this->counterpart($member), $query, $unread);
    }

    public function showWithdrawn(Request $request, ConversationMessages $query, ConversationUnreadSnapshot $unread): InertiaResponse
    {
        return $this->conversation($request, null, $query, $unread);
    }

    /**
     * There is no such route for the withdrawn bucket, which names no member to deliver to. The
     * message comes back in the shape the paging endpoint uses, so the composer appends it rather
     * than re-reading the page.
     */
    public function store(StoreChatMessageRequest $request, Member $member, SendDirectMessage $action): JsonResponse
    {
        $viewer = $this->viewer();
        $counterpart = $this->counterpart($member);

        try {
            $message = $action(
                $viewer,
                new DirectMessageComposeData($counterpart->getKey(), subject: null, body: $request->validated('body')),
                asDraft: false,
                images: $request->pickedImages(),
            );
        } catch (DirectMessageActionException) {
            // Reported against `body` so the composer keeps the whole draft it is still holding.
            throw ValidationException::withMessages(['body' => __('Cannot send the message.')]);
        }

        $message->setRelation('sender', $viewer->loadMissing('avatar.file'));
        // PostImages creates the join rows through the relation without populating it, so both are
        // loaded here rather than lazily in the serializer.
        $message->load(['files.file', 'recipients']);

        return response()->json(ConversationMessageSerializer::message($message, $viewer, $counterpart), 201);
    }

    /** Fire-and-forget: no body comes back, and the shell's own refresh is what moves the badge. */
    public function read(MarkConversationReadRequest $request, Member $member, MarkConversationRead $action, DirectMessageNotificationRows $feedRows): Response
    {
        return $this->markRead($request, $this->counterpart($member), $action, $feedRows);
    }

    public function readWithdrawn(MarkConversationReadRequest $request, MarkConversationRead $action, DirectMessageNotificationRows $feedRows): Response
    {
        return $this->markRead($request, null, $action, $feedRows);
    }

    /** An empty conversation is not a 404: the member asked for it to be gone, and it is. */
    public function delete(Member $member, DeleteConversation $action): RedirectResponse
    {
        return $this->deleteConversation($this->counterpart($member), $action);
    }

    public function deleteWithdrawn(DeleteConversation $action): RedirectResponse
    {
        return $this->deleteConversation(null, $action);
    }

    /**
     * A cursor that does not parse is read as no cursor: pagination is a position, not a permission,
     * and the conversation's own predicate already decided which rows exist for this reader.
     */
    public function messages(Request $request, Member $member, ConversationMessages $query): JsonResponse
    {
        return $this->page($request, $this->counterpart($member), $query);
    }

    public function withdrawnMessages(Request $request, ConversationMessages $query): JsonResponse
    {
        return $this->page($request, null, $query);
    }

    /** Self-addressing is a 404, as OpenPNE 3 answered it. */
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
            // A refused pair gets no composer rather than one shown dead.
            'canSend' => $counterpart !== null && DirectMessageAccess::canSend($viewer, $counterpart),
            // Independent of the slice `?m=` opened: a link names a message, the boundary names what
            // has not been read.
            'unreadSnapshot' => ConversationMessageSerializer::unreadSnapshot($unread($viewer, $counterpart)),
        ]);
    }

    private function deleteConversation(?Member $counterpart, DeleteConversation $action): RedirectResponse
    {
        $action($this->viewer(), $counterpart);

        return redirect()->route('message.chat.index')->with('status', __('Deleted the conversation.'));
    }

    private function markRead(MarkConversationReadRequest $request, ?Member $counterpart, MarkConversationRead $action, DirectMessageNotificationRows $feedRows): Response
    {
        try {
            $action($this->viewer(), $counterpart, (int) $request->validated('messageId'));
        } catch (DirectMessageActionException) {
            // A stale id — trashed from the mailbox mid-visit, or another conversation's — is an
            // ordinary race rather than an error worth its own answer.
            abort(404);
        }

        $feedRows->markReadFor($this->viewer());

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
     * Best-effort: a `?m=` this conversation cannot see, or one that does not parse, opens the newest
     * page rather than refusing.
     */
    private function anchor(Member $viewer, ?Member $counterpart, mixed $id): ?DirectMessage
    {
        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        return ConversationScope::apply(DirectMessage::query(), $viewer, $counterpart)->find((int) $id);
    }
}
