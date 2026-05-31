<?php

namespace App\Features\Block;

use App\Compat\RouteParityRegistry;
use App\Features\Block\Actions\BlockMember;
use App\Features\Block\Actions\UnblockMember;
use App\Features\Block\Exceptions\BlockActionException;
use App\Features\Block\Exceptions\BlockActionFailure;
use App\Features\Block\Queries\ListBlocks;
use App\Features\Block\Serializers\BlockSerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Block\BlockRequest;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class BlockController extends Controller
{
    public function list(Request $request, ListBlocks $query): View|InertiaResponse
    {
        $blocks = $query($this->viewer());

        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('block.list', [
                'blocks' => $blocks,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('block/list', [
                'blocks' => BlockSerializer::paginator($blocks),
            ]),
        ]);
    }

    public function showAdd(Request $request): View|InertiaResponse
    {
        $viewer = $this->viewer();
        $target = Member::findOrFail((int) $request->query('id'));

        if ($viewer->is($target) || BlockLookup::ownerBlocksViewer($viewer, $target)) {
            abort(404);
        }

        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('block.add', [
                'target' => $target,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('block/add', [
                'target' => BlockSerializer::member($target),
            ]),
        ]);
    }

    public function submitAdd(BlockRequest $request, BlockMember $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $request->target());
        } catch (BlockActionException $e) {
            return $this->redirectAfterSubmit($request, 'block.list', error: $this->messageFor($e->reason));
        }

        return $this->redirectAfterSubmit($request, 'block.list', status: __('Member blocked.'));
    }

    public function showRemove(Request $request, Member $member): View|InertiaResponse
    {
        if (! BlockLookup::ownerBlocksViewer($this->viewer(), $member)) {
            abort(404);
        }

        return $this->respondWith($request, [
            SurfaceResolver::CLASSIC => fn () => view('block.remove', [
                'target' => $member,
            ]),
            SurfaceResolver::MODERN => fn () => Inertia::render('block/remove', [
                'target' => BlockSerializer::member($member),
            ]),
        ]);
    }

    public function submitRemove(Request $request, Member $member, UnblockMember $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $member);
        } catch (BlockActionException $e) {
            return $this->redirectAfterSubmit($request, 'block.list', error: $this->messageFor($e->reason));
        }

        return $this->redirectAfterSubmit($request, 'block.list', status: __('Member unblocked.'));
    }

    private function redirectAfterSubmit(Request $request, string $canonicalName, ?string $status = null, ?string $error = null): RedirectResponse
    {
        $redirect = redirect()->route(SurfaceResolver::redirectName($request, $canonicalName));
        if ($status !== null) {
            $redirect = $redirect->with('status', $status);
        }
        if ($error !== null) {
            $redirect = $redirect->with('error', $error);
        }

        return $redirect;
    }

    /**
     * @param  array{classic: callable(): (View|InertiaResponse), modern: callable(): (View|InertiaResponse)}  $responders
     */
    private function respondWith(Request $request, array $responders): View|InertiaResponse
    {
        $response = $responders[SurfaceResolver::resolve($request, 'block')]();

        // Classic body id is the OpenPNE 3 page_{module}_{action} hook, derived from the route
        // parity. Canonicalize first so a /m/* route that fell back to Classic (carrying the
        // modern name) still resolves to the canonical parity key.
        if ($response instanceof View) {
            $name = SurfaceResolver::canonicalName($request->route()->getName());
            $response->with('pageId', RouteParityRegistry::bodyId($name));
        }

        return $response;
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }

    private function messageFor(BlockActionFailure $reason): string
    {
        return match ($reason) {
            BlockActionFailure::SelfBlock => __('You cannot block yourself.'),
            BlockActionFailure::AlreadyBlocked => __('This member is already blocked.'),
            BlockActionFailure::NotBlocked => __('This member is not blocked.'),
        };
    }
}
