<?php

namespace App\Features\Member;

use App\Features\Member\Actions\RemoveAvatar;
use App\Features\Member\Actions\SetAvatar;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\AvatarRequest;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Member profile image (avatar), on both surfaces: the Classic editor and the Modern Inertia page
 * share one upload/remove backend (SetAvatar / RemoveAvatar). Post-submit redirects stay on the
 * surface they came from via SurfaceResolver::redirectName.
 */
class MemberAvatarController extends Controller
{
    use RespondsWithSurface;

    public function edit(Request $request): View|InertiaResponse
    {
        return $this->respondWith($request, 'member', [
            SurfaceResolver::CLASSIC => fn (): View => view('member.avatar', ['avatar' => $this->viewer()->avatar?->file]),
            SurfaceResolver::MODERN => fn (): InertiaResponse => Inertia::render('member/avatar', ['avatar' => $this->avatarImage()]),
        ]);
    }

    public function update(AvatarRequest $request, SetAvatar $action): RedirectResponse
    {
        $action($this->viewer(), $request->file('image'));

        return redirect()->route(SurfaceResolver::redirectName($request, 'member.avatar.edit'))
            ->with('status', __('Profile image updated.'));
    }

    public function destroy(Request $request, RemoveAvatar $action): RedirectResponse
    {
        $action($this->viewer());

        return redirect()->route(SurfaceResolver::redirectName($request, 'member.avatar.edit'))
            ->with('status', __('Profile image removed.'));
    }

    /**
     * The viewer's avatar as the shared Modern image shape, or null when unset. thumbnailUrl is the
     * 180px square editor preview; url is the full-bytes (FilePolicy-gated) original.
     *
     * @return array{url: string, thumbnailUrl: string}|null
     */
    private function avatarImage(): ?array
    {
        $file = $this->viewer()->avatar?->file;

        return $file ? ['url' => $file->url(), 'thumbnailUrl' => $file->thumbnailUrl(180, 180, square: true)] : null;
    }

    private function viewer(): Member
    {
        $viewer = auth()->user();
        assert($viewer instanceof Member);

        return $viewer;
    }
}
