<?php

namespace App\Features\DirectMessage;

use App\Compat\RouteParityRegistry;
use App\Features\DirectMessage\Actions\PurgeDirectMessages;
use App\Features\DirectMessage\Actions\RestoreDirectMessages;
use App\Features\DirectMessage\Actions\SendDirectMessage;
use App\Features\DirectMessage\Actions\TrashDirectMessages;
use App\Features\DirectMessage\Actions\UpdateDraft;
use App\Features\DirectMessage\Exceptions\DirectMessageActionException;
use App\Features\DirectMessage\Exceptions\DirectMessageActionFailure;
use App\Features\DirectMessage\Queries\ListDirectMessages;
use App\Features\DirectMessage\Queries\ShowDirectMessage;
use App\Features\DirectMessage\Serializers\DirectMessageSerializer;
use App\Files\ImageEdit;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\DirectMessage\BulkDirectMessageRequest;
use App\Http\Requests\DirectMessage\ComposeDirectMessageRequest;
use App\Http\Requests\DirectMessage\UpdateDraftRequest;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Private messages, the OpenPNE 3 message module: the four boxes, the per-message show, composing
 * (compose/reply/send), draft editing, and the trash actions (single-message and bulk
 * trash/restore/purge).
 *
 * **The reading pages are Classic's alone.** A Modern viewer reads the same store as chat
 * (ConversationController), so every GET here that has a chat equivalent answers them with a
 * redirect into it rather than a second reading of the same rows — the URLs stay OpenPNE 3's,
 * durable and already in members' mail. What Modern still renders from this controller is the draft
 * form, which no conversation holds; what Classic still renders is every page, unchanged, through
 * the classic() helper with the OpenPNE 3 page_message_* body id.
 *
 * The write actions are surface-agnostic and redirect on the surface they came from.
 */
class DirectMessageController extends Controller
{
    use RespondsWithSurface;

    /** OpenPNE 3 message/index forwards to the inbox (staying on the request's surface). */
    public function index(Request $request): RedirectResponse
    {
        return $this->isModern($request)
            ? redirect()->route('message.chat.index')
            : redirect()->route('message.receive');
    }

    public function receive(Request $request, ListDirectMessages $query): View|RedirectResponse
    {
        return $this->list($request, DirectMessageBox::Receive, $query);
    }

    public function send(Request $request, ListDirectMessages $query): View|RedirectResponse
    {
        return $this->list($request, DirectMessageBox::Sent, $query);
    }

    public function draft(Request $request, ListDirectMessages $query): View|RedirectResponse
    {
        return $this->list($request, DirectMessageBox::Draft, $query);
    }

    public function trash(Request $request, ListDirectMessages $query): View|RedirectResponse
    {
        return $this->list($request, DirectMessageBox::Trash, $query);
    }

    public function showReceived(Request $request, int $message, ShowDirectMessage $query): View|RedirectResponse
    {
        return $this->show($request, DirectMessageBox::Receive, $message, $query);
    }

    public function showSent(Request $request, int $message, ShowDirectMessage $query): View|RedirectResponse
    {
        return $this->show($request, DirectMessageBox::Sent, $message, $query);
    }

    public function showTrashed(Request $request, int $message, ShowDirectMessage $query): View|RedirectResponse
    {
        return $this->show($request, DirectMessageBox::Trash, $message, $query);
    }

    /** Compose a new message to a member (OpenPNE 3 sendToFriend?id=). */
    public function compose(Request $request): View|RedirectResponse
    {
        $recipient = Member::find((int) $request->query('id'));

        if ($this->isModern($request)) {
            // Chat writes in the conversation, so this is a way into one. A missing or self-addressed
            // recipient names no conversation and lands on the list — there is nothing here for a
            // 404 to tell the member to do.
            return $recipient === null || $this->viewer()->is($recipient)
                ? redirect()->route('message.chat.index')
                : redirect()->route('message.chat.show', ['member' => $recipient->getKey()]);
        }

        abort_if($recipient === null || $this->viewer()->is($recipient), 404);

        return $this->composeForm($recipient);
    }

    public function store(ComposeDirectMessageRequest $request, SendDirectMessage $action): RedirectResponse
    {
        try {
            $message = $action($this->viewer(), $request->toData(), $request->asDraft(), $request->file('images', []));
        } catch (DirectMessageActionException $e) {
            return $this->failed($e);
        }

        return $this->afterWrite($message->is_draft);
    }

    /** Reply to a received message: compose to its sender, carrying the thread links (OpenPNE 3 reply). */
    public function reply(Request $request, int $message): View|RedirectResponse
    {
        if ($this->isModern($request)) {
            // Answering is writing in the conversation, and the quote and the thread links are the
            // mailbox form's own — a conversation is linear and reads neither.
            return $this->conversationOf($message);
        }

        $original = DirectMessage::with('recipients')->findOrFail($message);
        $viewer = $this->viewer();
        // Reply is an inbox action: only on a live received message. A trashed or purged receipt has
        // left the inbox, so its body must not resurface as a quote (purge revokes the viewer's view).
        abort_unless(! $original->is_draft && $this->hasLiveInboxReceipt($original, $viewer), 404);
        abort_if($original->sender === null, 404); // a withdrawn sender cannot be replied to

        return $this->composeForm(
            $original->sender,
            parentId: (int) $original->getKey(),
            threadId: $original->thread_id !== null ? (int) $original->thread_id : (int) $original->getKey(),
            // Reply prefills "Re:" + the original subject and the body quoted line-by-line.
            subject: 'Re:'.(string) $original->subject,
            body: $this->quote((string) $original->body),
        );
    }

    /** Edit one of the viewer's own drafts (OpenPNE 3 edit). */
    public function edit(Request $request, int $message): View|InertiaResponse
    {
        $draft = DirectMessage::with(['files.file', 'draftRecipient'])->findOrFail($message);
        abort_unless($this->ownsLiveDraft($draft), 404);

        return $this->respondWith($request, 'directMessage', [
            SurfaceResolver::CLASSIC => fn () => view('message.edit', [
                'draft' => $draft,
                'recipient' => $draft->draftRecipient,
            ]),
            SurfaceResolver::MODERN => function () use ($draft) {
                $draft->loadMissing('draftRecipient.avatar.file');

                return Inertia::render('message/edit', ['draft' => DirectMessageSerializer::draftForm($draft)]);
            },
        ]);
    }

    public function update(UpdateDraftRequest $request, int $message, UpdateDraft $action): RedirectResponse
    {
        $draft = DirectMessage::findOrFail($message);

        try {
            $action(
                $this->viewer(), $draft,
                (string) $request->validated('subject'), (string) $request->validated('body'),
                $request->asDraft(), ImageEdit::fromRequest($request),
            );
        } catch (DirectMessageActionException $e) {
            return $this->failed($e);
        }

        return $this->afterWrite($draft->is_draft);
    }

    /** Move a received message to the trash (OpenPNE 3 deleteReceiveMessage). */
    public function trashReceived(Request $request, int $message, TrashDirectMessages $action): RedirectResponse
    {
        abort_if($action($this->viewer(), DirectMessageBox::Receive, [$message]) === 0, 404);

        return redirect()->route('message.receive')->with('status', __('The message was moved to the trash.'));
    }

    /** Move a sent message to the trash (OpenPNE 3 deleteSendMessage). */
    public function trashSent(Request $request, int $message, TrashDirectMessages $action): RedirectResponse
    {
        abort_if($action($this->viewer(), DirectMessageBox::Sent, [$message]) === 0, 404);

        return redirect()->route('message.send')->with('status', __('The message was moved to the trash.'));
    }

    /** Restore a trashed message to its box (OpenPNE 3 restore). */
    public function restore(Request $request, int $message, RestoreDirectMessages $action): RedirectResponse
    {
        abort_if($action($this->viewer(), [$message]) === 0, 404);

        return redirect()->route('message.trash')->with('status', __('The message was restored.'));
    }

    /** Confirm purging a single trashed message (OpenPNE 3 deleteConfirmDustMessage). Modern has no trash screen. */
    public function purgeConfirm(Request $request, int $message, ShowDirectMessage $query): View|RedirectResponse
    {
        if ($this->isModern($request)) {
            return $this->conversationOf($message);
        }

        $view = $query($this->viewer(), DirectMessageBox::Trash, $message);
        abort_if($view === null, 404);

        return $this->classic('message.purge_confirm', ['message' => $view->message]);
    }

    /** Purge a single trashed message (OpenPNE 3 deleteDustMessage). */
    public function purge(Request $request, int $message, PurgeDirectMessages $action): RedirectResponse
    {
        abort_if($action($this->viewer(), [$message]) === 0, 404);

        return redirect()->route('message.trash')->with('status', __('The message was deleted.'));
    }

    /**
     * Bulk action over a list's checked rows (OpenPNE 3 MessageDeleteForm): trash from the
     * receive/send/draft boxes, restore or purge from the trash box. Classic gates a purge behind a
     * confirmation page (first submit renders it, confirmed submit carries it out); Modern confirms
     * inline, so its purge always arrives confirmed. Every redirect stays on the request's surface.
     */
    public function bulk(BulkDirectMessageRequest $request, TrashDirectMessages $trash, RestoreDirectMessages $restore, PurgeDirectMessages $purge): View|RedirectResponse
    {
        $viewer = $this->viewer();
        $box = $request->box();
        $ids = $request->ids();
        $trashList = 'message.trash';

        if ($ids === []) {
            return redirect()->route($box->listRoute());
        }

        if ($box !== DirectMessageBox::Trash) {
            $trash($viewer, $box, $ids);

            return redirect()->route($box->listRoute())->with('status', __('The message was moved to the trash.'));
        }

        if ($request->action() === 'restore') {
            $restore($viewer, $ids);

            return redirect()->route($trashList)->with('status', __('The message was restored.'));
        }

        if (! $request->confirmed()) {
            // Classic renders the confirm page; a Modern purge is always confirmed, so an unconfirmed
            // one here is a client error — nothing is purged.
            if (SurfaceResolver::resolve($request, 'directMessage') === SurfaceResolver::CLASSIC) {
                return $this->classic('message.bulk_purge_confirm', ['ids' => $ids]);
            }

            return redirect()->route($trashList);
        }

        $purge($viewer, $ids);

        return redirect()->route($trashList)->with('status', __('The message was deleted.'));
    }

    /** The OpenPNE 3 compose form, Classic's alone: a Modern viewer writes in the conversation. */
    private function composeForm(Member $recipient, ?int $parentId = null, ?int $threadId = null, string $subject = '', string $body = ''): View
    {
        return $this->classic('message.compose', [
            'recipient' => $recipient,
            'parentId' => $parentId,
            'threadId' => $threadId,
            'subject' => $subject,
            'body' => $body,
        ]);
    }

    /** OpenPNE 3 reply quote: each line of the original body prefixed "> " (empty stays empty). */
    private function quote(string $body): string
    {
        return $body === '' ? '' : '> '.str_replace("\n", "\n> ", $body);
    }

    /** A draft the viewer may edit: their own, still a draft, and not trashed/purged. */
    private function ownsLiveDraft(DirectMessage $draft): bool
    {
        return (int) $draft->sender_id === (int) $this->viewer()->getKey()
            && $draft->is_draft
            && $draft->sender_deleted_at === null
            && $draft->sender_purged_at === null;
    }

    /** After a write: the sent box for a sent message, the draft box for a saved draft (same surface). */
    private function afterWrite(bool $isDraft): RedirectResponse
    {
        return $isDraft
            ? $this->redirectAfterSubmit('message.draft', status: __('The message was saved successfully.'))
            : $this->redirectAfterSubmit('message.send', status: __('The message was sent successfully.'));
    }

    /** OpenPNE 3 flashes an error and returns to the sent box when a send is blocked. */
    private function failed(DirectMessageActionException $e): RedirectResponse
    {
        if ($e->reason === DirectMessageActionFailure::CannotSend) {
            return $this->redirectAfterSubmit('message.send', error: __('Cannot send the message.'));
        }

        abort(404); // too many images: a payload past the cross-field cap
    }

    /** The viewer has a live inbox receipt (delivered, not trashed, not purged) for this message. */
    private function hasLiveInboxReceipt(DirectMessage $message, Member $viewer): bool
    {
        return $message->recipients->contains(
            fn (DirectMessageRecipient $r): bool => (int) $r->recipient_id === (int) $viewer->getKey()
                && $r->recipient_deleted_at === null
                && $r->recipient_purged_at === null
        );
    }

    /** Whether this request is answered as chat rather than as the mailbox. */
    private function isModern(Request $request): bool
    {
        return SurfaceResolver::resolve($request, 'directMessage') === SurfaceResolver::MODERN;
    }

    /**
     * Where a mailbox URL naming one message lands on the chat surface: the conversation it belongs
     * to, seen from the viewer's side — the counterpart is the recipient of what they sent and the
     * sender of what they received, and a null one is the withdrawn bucket. `$anchor` carries the
     * message on as `?m=`, which the conversation honours best-effort.
     *
     * 404 unless the viewer is a party to the message. These ids are sequential and the URLs are
     * public, so a redirect that named the other side would answer "who is member N corresponding
     * with" for any id — something the boxes' own gates never let through. A draft belongs to no
     * conversation at all, only to the form that is still writing it.
     */
    private function conversationOf(int $messageId, bool $anchor = false): RedirectResponse
    {
        $message = DirectMessage::with('recipients')->findOrFail($messageId);
        $viewerId = (int) $this->viewer()->getKey();
        $viewerIsSender = (int) $message->sender_id === $viewerId;

        abort_if($message->is_draft, 404);
        abort_unless(
            $viewerIsSender || $message->recipients->contains(
                fn (DirectMessageRecipient $receipt): bool => (int) $receipt->recipient_id === $viewerId
            ),
            404,
        );

        // An upgraded multi-recipient send sits in several conversations; a URL can land in one. The
        // lowest receipt id — the first delivery written — is the rule, fixed here rather than left
        // to whatever order the relation loaded in.
        $counterpartId = $viewerIsSender
            ? $message->recipients->sortBy('id')->first()?->recipient_id
            : $message->sender_id;
        $query = $anchor ? ['m' => $messageId] : [];

        return $counterpartId === null
            ? redirect()->route('message.chat.withdrawn', $query)
            : redirect()->route('message.chat.show', ['member' => (int) $counterpartId] + $query);
    }

    private function list(Request $request, DirectMessageBox $box, ListDirectMessages $query): View|RedirectResponse
    {
        // The boxes are the mailbox's reading of the store; chat's is the conversation list, which
        // carries the drafts box as a section of itself.
        if ($this->isModern($request)) {
            // A submit still lands on its box and is forwarded from here, so the flash it wrote has
            // to survive the extra hop — otherwise every write on this surface loses its answer.
            $request->session()->reflash();

            return redirect()->route('message.chat.index');
        }

        return $this->classic('message.list', [
            'box' => $box,
            'messages' => $query($this->viewer(), $box, withRepliedStatus: true),
        ]);
    }

    private function show(Request $request, DirectMessageBox $box, int $messageId, ShowDirectMessage $query): View|RedirectResponse
    {
        // Resolved before the box query, which marks a received message read: the conversation draws
        // its own unread boundary, and a read stamped on the way past would erase the line the
        // reader is being sent to.
        if ($this->isModern($request)) {
            return $this->conversationOf($messageId, anchor: true);
        }

        $view = $query($this->viewer(), $box, $messageId);
        abort_if($view === null, 404);

        return $this->classic('message.show', ['view' => $view]);
    }

    /** Render a Classic view with the OpenPNE 3 page_{module}_{action} body id from the parity. */
    private function classic(string $view, array $data = []): View
    {
        return view($view, $data)->with('pageId', RouteParityRegistry::bodyId($this->routeName()));
    }

    private function routeName(): string
    {
        $route = request()->route();

        return $route !== null ? (string) $route->getName() : '';
    }
}
