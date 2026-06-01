<?php

namespace App\Features\Member;

use App\Compat\RouteParityRegistry;
use App\Features\Member\Actions\SetAvatar;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\AvatarRequest;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Member profile image (avatar). Classic surface only for now; a Modern adapter is
 * deferred (status: none) until the member profile area is built out.
 */
class MemberAvatarController extends Controller
{
    public function edit(): View
    {
        // Classic body id is the OpenPNE 3 page_member_configImage hook (MemberRouteParity).
        return view('member.avatar', [
            'avatar' => $this->viewer()->primaryImage?->file,
        ])->with('pageId', RouteParityRegistry::bodyId('member.avatar.edit'));
    }

    public function update(AvatarRequest $request, SetAvatar $action): RedirectResponse
    {
        $action($this->viewer(), $request->file('image'));

        return redirect()->route('member.avatar.edit')->with('status', __('Profile image updated.'));
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
