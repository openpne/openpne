<?php

namespace App\Features\Block;

use App\Features\Block\Actions\BlockMember;
use App\Features\Block\Actions\UnblockMember;
use App\Features\Block\Exceptions\BlockActionException;
use App\Features\Block\Exceptions\BlockActionFailure;
use App\Features\Block\Queries\ListBlocks;
use App\Http\Controllers\Controller;
use App\Http\Requests\Block\BlockRequest;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BlockController extends Controller
{
    public function list(ListBlocks $query): View
    {
        return view('block.list', [
            'pageId' => 'page_block_list',
            'blocks' => $query($this->viewer()),
        ]);
    }

    public function showAdd(Request $request): View|RedirectResponse
    {
        $viewer = $this->viewer();
        $target = Member::findOrFail((int) $request->query('id'));

        if ($viewer->is($target) || BlockLookup::ownerBlocksViewer($viewer, $target)) {
            abort(404);
        }

        return view('block.add', [
            'pageId' => 'page_block_add',
            'target' => $target,
        ]);
    }

    public function submitAdd(BlockRequest $request, BlockMember $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $request->target());
        } catch (BlockActionException $e) {
            return redirect()->route('block.list')->with('error', $this->messageFor($e->reason));
        }

        return redirect()->route('block.list')->with('status', __('Member blocked.'));
    }

    public function showRemove(Member $member): View
    {
        if (! BlockLookup::ownerBlocksViewer($this->viewer(), $member)) {
            abort(404);
        }

        return view('block.remove', [
            'pageId' => 'page_block_remove',
            'target' => $member,
        ]);
    }

    public function submitRemove(Member $member, UnblockMember $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $member);
        } catch (BlockActionException $e) {
            return redirect()->route('block.list')->with('error', $this->messageFor($e->reason));
        }

        return redirect()->route('block.list')->with('status', __('Member unblocked.'));
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
