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
 * Private messages (OpenPNE 3 message module), dual-surface across the read pages (the four boxes and
 * the per-message show), composing (compose/reply/send), draft editing, and the trash actions
 * (single-message and bulk trash/restore/purge): each serves Classic Blade or Modern Inertia per
 * SurfaceResolver, and a submit redirects on the surface it came from. Modern confirms a purge inline,
 * so only the GET confirm pages (purgeConfirm, bulk purge) stay Classic-only, rendered through the
 * classic() helper with the OpenPNE 3 page_message_* body id.
 */
class DirectMessageController extends Controller
{
    use RespondsWithSurface;

    /** OpenPNE 3 message/index forwards to the inbox (staying on the request's surface). */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('message.receive');
    }

    public function receive(Request $request, ListDirectMessages $query): View|InertiaResponse
    {
        return $this->list($request, DirectMessageBox::Receive, $query);
    }

    public function send(Request $request, ListDirectMessages $query): View|InertiaResponse
    {
        return $this->list($request, DirectMessageBox::Sent, $query);
    }

    public function draft(Request $request, ListDirectMessages $query): View|InertiaResponse
    {
        return $this->list($request, DirectMessageBox::Draft, $query);
    }

    public function trash(Request $request, ListDirectMessages $query): View|InertiaResponse
    {
        return $this->list($request, DirectMessageBox::Trash, $query);
    }

    public function showReceived(Request $request, int $message, ShowDirectMessage $query): View|InertiaResponse
    {
        return $this->show($request, DirectMessageBox::Receive, $message, $query);
    }

    public function showSent(Request $request, int $message, ShowDirectMessage $query): View|InertiaResponse
    {
        return $this->show($request, DirectMessageBox::Sent, $message, $query);
    }

    public function showTrashed(Request $request, int $message, ShowDirectMessage $query): View|InertiaResponse
    {
        return $this->show($request, DirectMessageBox::Trash, $message, $query);
    }

    /** Compose a new message to a member (OpenPNE 3 sendToFriend?id=). */
    public function compose(Request $request): View|InertiaResponse
    {
        $recipient = Member::find((int) $request->query('id'));
        abort_if($recipient === null || $this->viewer()->is($recipient), 404);

        return $this->composeForm($request, $recipient);
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
    public function reply(Request $request, int $message): View|InertiaResponse
    {
        $original = DirectMessage::with('recipients')->findOrFail($message);
        $viewer = $this->viewer();
        // Reply is an inbox action: only on a live received message. A trashed or purged receipt has
        // left the inbox, so its body must not resurface as a quote (purge revokes the viewer's view).
        abort_unless(! $original->is_draft && $this->hasLiveInboxReceipt($original, $viewer), 404);
        abort_if($original->sender === null, 404); // a withdrawn sender cannot be replied to

        return $this->composeForm(
            $request,
            $original->sender,
            parentId: (int) $original->getKey(),
            threadId: $original->thread_id !== null ? (int) $original->thread_id : (int) $original->getKey(),
            // Reply prefills "Re:" + the original subject and the body quoted line-by-line.
            subject: 'Re:'.(string) $original->subject,
            body: $this->quote((string) $original->body),
            parentSubject: (string) $original->subject,
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

    /** Confirm purging a single trashed message (OpenPNE 3 deleteConfirmDustMessage). Modern confirms inline. */
    public function purgeConfirm(Request $request, int $message, ShowDirectMessage $query): View|RedirectResponse
    {
        $view = $query($this->viewer(), DirectMessageBox::Trash, $message);
        abort_if($view === null, 404);

        // Modern confirms purging inline — send a Modern viewer back to the trashed message.
        if (SurfaceResolver::resolve($request, 'directMessage') === SurfaceResolver::MODERN) {
            return redirect()->route('message.trash.show', ['message' => $message]);
        }

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

    private function composeForm(Request $request, Member $recipient, ?int $parentId = null, ?int $threadId = null, string $subject = '', string $body = '', ?string $parentSubject = null): View|InertiaResponse
    {
        return $this->respondWith($request, 'directMessage', [
            SurfaceResolver::CLASSIC => fn () => view('message.compose', [
                'recipient' => $recipient,
                'parentId' => $parentId,
                'threadId' => $threadId,
                'subject' => $subject,
                'body' => $body,
            ]),
            SurfaceResolver::MODERN => function () use ($recipient, $parentId, $threadId, $subject, $body, $parentSubject) {
                $recipient->loadMissing('avatar.file');

                return Inertia::render('message/compose', [
                    'recipient' => DirectMessageSerializer::memberRef($recipient),
                    'parentId' => $parentId,
                    'threadId' => $threadId,
                    'subject' => $subject,
                    'body' => $body,
                    // The reply crumb's label (the original subject, before the "Re:" prefix above);
                    // null on a fresh compose.
                    'parentSubject' => $parentSubject,
                ]);
            },
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

    private function list(Request $request, DirectMessageBox $box, ListDirectMessages $query): View|InertiaResponse
    {
        // The query runs inside the chosen closure: only Classic draws the status icons, so only
        // it pays the replied lookup.
        return $this->respondWith($request, 'directMessage', [
            SurfaceResolver::CLASSIC => fn () => view('message.list', [
                'box' => $box,
                'messages' => $query($this->viewer(), $box, withRepliedStatus: true),
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('message/index', [
                'box' => $box->value,
                'messages' => DirectMessageSerializer::paginator($query($this->viewer(), $box)),
            ]),
        ]);
    }

    private function show(Request $request, DirectMessageBox $box, int $messageId, ShowDirectMessage $query): View|InertiaResponse
    {
        $view = $query($this->viewer(), $box, $messageId);
        abort_if($view === null, 404);

        return $this->respondWith($request, 'directMessage', [
            SurfaceResolver::CLASSIC => fn () => view('message.show', ['view' => $view]),
            SurfaceResolver::MODERN => fn () => Inertia::render('message/show', ['message' => DirectMessageSerializer::view($view)]),
        ]);
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
