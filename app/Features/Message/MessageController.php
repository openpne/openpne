<?php

namespace App\Features\Message;

use App\Compat\RouteParityRegistry;
use App\Features\Message\Actions\SendMessage;
use App\Features\Message\Actions\UpdateDraft;
use App\Features\Message\Exceptions\MessageActionException;
use App\Features\Message\Exceptions\MessageActionFailure;
use App\Features\Message\Queries\ListMessages;
use App\Features\Message\Queries\ShowMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Message\ComposeMessageRequest;
use App\Http\Requests\Message\UpdateDraftRequest;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Classic-only adapter for private messages (OpenPNE 3 message module). Modern is status `none` —
 * no /m/* routes, no Inertia — so this renders Blade directly with the OpenPNE 3 page_message_*
 * body id. PR1 is the read surface (the four boxes + show); compose/reply/delete are the write
 * surface (PR2).
 */
class MessageController extends Controller
{
    /** OpenPNE 3 message/index forwards to the inbox. */
    public function index(): RedirectResponse
    {
        return redirect()->route('message.receive');
    }

    public function receive(ListMessages $query): View
    {
        return $this->list(MessageBox::Receive, $query);
    }

    public function send(ListMessages $query): View
    {
        return $this->list(MessageBox::Sent, $query);
    }

    public function draft(ListMessages $query): View
    {
        return $this->list(MessageBox::Draft, $query);
    }

    public function trash(ListMessages $query): View
    {
        return $this->list(MessageBox::Trash, $query);
    }

    public function showReceived(int $message, ShowMessage $query): View
    {
        return $this->show(MessageBox::Receive, $message, $query);
    }

    public function showSent(int $message, ShowMessage $query): View
    {
        return $this->show(MessageBox::Sent, $message, $query);
    }

    public function showTrashed(int $message, ShowMessage $query): View
    {
        return $this->show(MessageBox::Trash, $message, $query);
    }

    /** Compose a new message to a member (OpenPNE 3 sendToFriend?id=). */
    public function compose(Request $request): View
    {
        $recipient = Member::find((int) $request->query('id'));
        abort_if($recipient === null || $this->viewer()->is($recipient), 404);

        return $this->composeForm($recipient);
    }

    public function store(ComposeMessageRequest $request, SendMessage $action): RedirectResponse
    {
        try {
            $message = $action($this->viewer(), $request->toData(), $request->asDraft(), $request->file('images', []));
        } catch (MessageActionException $e) {
            return $this->failed($e);
        }

        return $this->afterWrite($message->is_draft);
    }

    /** Reply to a received message: compose to its sender, carrying the thread links (OpenPNE 3 reply). */
    public function reply(int $message): View
    {
        $original = Message::with('recipients')->findOrFail($message);
        $viewer = $this->viewer();
        abort_unless(! $original->is_draft && $this->isRecipient($original, $viewer), 404);
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
    public function edit(int $message): View
    {
        $draft = Message::with(['files.file', 'recipients.recipient'])->findOrFail($message);
        abort_unless($this->ownsLiveDraft($draft), 404);

        return $this->classic('message.edit', [
            'draft' => $draft,
            'recipient' => $draft->recipients->first()?->recipient,
        ]);
    }

    public function update(UpdateDraftRequest $request, int $message, UpdateDraft $action): RedirectResponse
    {
        $draft = Message::findOrFail($message);

        try {
            $action(
                $this->viewer(), $draft,
                (string) $request->validated('subject'), (string) $request->validated('body'),
                $request->asDraft(), $request->file('images', []), $request->input('remove_images', []),
            );
        } catch (MessageActionException $e) {
            return $this->failed($e);
        }

        return $this->afterWrite($draft->is_draft);
    }

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
    private function ownsLiveDraft(Message $draft): bool
    {
        return (int) $draft->sender_id === (int) $this->viewer()->getKey()
            && $draft->is_draft
            && $draft->sender_deleted_at === null
            && $draft->sender_purged_at === null;
    }

    /** After a write: the sent box for a sent message, the draft box for a saved draft. */
    private function afterWrite(bool $isDraft): RedirectResponse
    {
        return $isDraft
            ? redirect()->route('message.draft')->with('status', __('The message was saved successfully.'))
            : redirect()->route('message.send')->with('status', __('The message was sent successfully.'));
    }

    /** OpenPNE 3 flashes an error and returns to the sent box when a send is blocked. */
    private function failed(MessageActionException $e): RedirectResponse
    {
        if ($e->reason === MessageActionFailure::CannotSend) {
            return redirect()->route('message.send')->with('error', __('Cannot send the message.'));
        }

        abort(404); // too many images: a payload past the cross-field cap
    }

    private function isRecipient(Message $message, Member $viewer): bool
    {
        return $message->recipients->contains(
            fn (MessageRecipient $r): bool => (int) $r->recipient_id === (int) $viewer->getKey()
        );
    }

    private function list(MessageBox $box, ListMessages $query): View
    {
        return $this->classic('message.list', [
            'box' => $box,
            'messages' => $query($this->viewer(), $box),
        ]);
    }

    private function show(MessageBox $box, int $messageId, ShowMessage $query): View
    {
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

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
