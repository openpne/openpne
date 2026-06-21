<?php

namespace App\Features\Message;

use App\Compat\RouteParityRegistry;
use App\Features\Message\Queries\ListMessages;
use App\Features\Message\Queries\ShowMessage;
use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
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
