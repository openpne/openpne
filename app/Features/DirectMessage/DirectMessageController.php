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
 * Every GET with a chat equivalent redirects a Modern viewer into it, the draft form being the one
 * page Modern still renders here
 * (`docs/internals/direct-messages.md`, "Modern reads the store as chat").
 */
class DirectMessageController extends Controller
{
    use RespondsWithSurface;

    /** OpenPNE 3 message/index forwards to the inbox. */
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

    /** OpenPNE 3 sendToFriend?id=. */
    public function compose(Request $request): View|RedirectResponse
    {
        $recipient = Member::find((int) $request->query('id'));

        if ($this->isModern($request)) {
            // A missing or self-addressed recipient names no conversation and lands on the list
            // rather than on the Classic branch's 404.
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

    /** OpenPNE 3 reply. */
    public function reply(Request $request, int $message): View|RedirectResponse
    {
        if ($this->isModern($request)) {
            // A conversation is linear, so the quote and the thread links stay the mailbox form's.
            return $this->conversationOf($message);
        }

        $original = DirectMessage::with('recipients')->findOrFail($message);
        $viewer = $this->viewer();
        // A trashed or purged receipt has left the inbox, so its body must not resurface as a quote.
        abort_unless(! $original->is_draft && $this->hasLiveInboxReceipt($original, $viewer), 404);
        abort_if($original->sender === null, 404); // a withdrawn sender cannot be replied to

        return $this->composeForm(
            $original->sender,
            parentId: (int) $original->getKey(),
            threadId: $original->thread_id !== null ? (int) $original->thread_id : (int) $original->getKey(),
            subject: 'Re:'.(string) $original->subject,
            body: $this->quote((string) $original->body),
        );
    }

    /** OpenPNE 3 edit. */
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

    /** OpenPNE 3 deleteReceiveMessage. */
    public function trashReceived(Request $request, int $message, TrashDirectMessages $action): RedirectResponse
    {
        abort_if($action($this->viewer(), DirectMessageBox::Receive, [$message]) === 0, 404);

        return redirect()->route('message.receive')->with('status', __('The message was moved to the trash.'));
    }

    /** OpenPNE 3 deleteSendMessage. */
    public function trashSent(Request $request, int $message, TrashDirectMessages $action): RedirectResponse
    {
        abort_if($action($this->viewer(), DirectMessageBox::Sent, [$message]) === 0, 404);

        return redirect()->route('message.send')->with('status', __('The message was moved to the trash.'));
    }

    /** OpenPNE 3 restore. */
    public function restore(Request $request, int $message, RestoreDirectMessages $action): RedirectResponse
    {
        abort_if($action($this->viewer(), [$message]) === 0, 404);

        return redirect()->route('message.trash')->with('status', __('The message was restored.'));
    }

    /** OpenPNE 3 deleteConfirmDustMessage; Modern has no trash screen to confirm on. */
    public function purgeConfirm(Request $request, int $message, ShowDirectMessage $query): View|RedirectResponse
    {
        if ($this->isModern($request)) {
            return $this->conversationOf($message);
        }

        $view = $query($this->viewer(), DirectMessageBox::Trash, $message);
        abort_if($view === null, 404);

        return $this->classic('message.purge_confirm', ['message' => $view->message]);
    }

    /** OpenPNE 3 deleteDustMessage. */
    public function purge(Request $request, int $message, PurgeDirectMessages $action): RedirectResponse
    {
        abort_if($action($this->viewer(), [$message]) === 0, 404);

        return redirect()->route('message.trash')->with('status', __('The message was deleted.'));
    }

    /** OpenPNE 3 MessageDeleteForm. */
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
            // A Modern purge is always confirmed, so an unconfirmed one here is a client error and
            // nothing is purged.
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

    private function ownsLiveDraft(DirectMessage $draft): bool
    {
        return (int) $draft->sender_id === (int) $this->viewer()->getKey()
            && $draft->is_draft
            && $draft->sender_deleted_at === null
            && $draft->sender_purged_at === null;
    }

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

    private function hasLiveInboxReceipt(DirectMessage $message, Member $viewer): bool
    {
        return $message->recipients->contains(
            fn (DirectMessageRecipient $r): bool => (int) $r->recipient_id === (int) $viewer->getKey()
                && $r->recipient_deleted_at === null
                && $r->recipient_purged_at === null
        );
    }

    private function isModern(Request $request): bool
    {
        return SurfaceResolver::resolve($request, 'directMessage') === SurfaceResolver::MODERN;
    }

    /**
     * 404 unless the viewer is a party to the message: the ids are sequential and the URLs public, so
     * a redirect naming the other side would answer "who is member N corresponding with" for any id.
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

        // An upgraded multi-recipient send sits in several conversations, so the lowest receipt id
        // fixes which one a URL lands in rather than the order the relation loaded in.
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
        if ($this->isModern($request)) {
            // A submit still lands on its box and is forwarded from here, so the flash it wrote has
            // to survive the extra hop.
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
        // Resolved before the box query, which marks a received message read: a read stamped on the
        // way past would erase the unread line the reader is being sent to.
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
