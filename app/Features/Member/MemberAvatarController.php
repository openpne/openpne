<?php

namespace App\Features\Member;

use App\Features\Member\Actions\RemoveAvatar;
use App\Features\Member\Actions\SetAvatar;
use App\Files\ImageMetadataStripException;
use App\Http\Controllers\Concerns\RespondsWithSurface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\AvatarRequest;
use App\Http\Requests\Member\UpdateAvatarColorRequest;
use App\Support\AvatarColor;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Member profile image (avatar), on both surfaces: the Classic editor and the Modern Inertia page
 * share one upload/remove backend (SetAvatar / RemoveAvatar). Post-submit redirects stay on the
 * surface they came from.
 */
class MemberAvatarController extends Controller
{
    use RespondsWithSurface;

    public function edit(Request $request): View|InertiaResponse
    {
        return $this->respondWith($request, 'member', [
            SurfaceResolver::CLASSIC => fn (): View => view('member.avatar', [
                'avatar' => $this->viewer()->avatar?->file,
                'maxUploadBytes' => AvatarRequest::MAX_KILOBYTES * 1024,
            ]),
            SurfaceResolver::MODERN => fn (): InertiaResponse => Inertia::render('member/avatar', [
                'avatar' => $this->avatarImage(),
                'badgeColor' => $this->badgeColor(),
            ]),
        ]);
    }

    public function update(AvatarRequest $request, SetAvatar $action): RedirectResponse
    {
        try {
            $action($this->viewer(), $request->file('image'));
        } catch (ImageMetadataStripException) {
            // SetAvatar uses FileUploader directly (no PostImages), so convert the fail-closed strip
            // to a validation error on the submitted field ('image', the avatar picker) here.
            throw ValidationException::withMessages(['image' => [ImageMetadataStripException::userMessage()]]);
        }

        return redirect()->route('member.avatar.edit')
            ->with('status', __('Profile image updated.'));
    }

    public function destroy(Request $request, RemoveAvatar $action): RedirectResponse
    {
        $action($this->viewer());

        return redirect()->route('member.avatar.edit')
            ->with('status', __('Profile image removed.'));
    }

    public function updateColor(UpdateAvatarColorRequest $request): RedirectResponse
    {
        // Not mass-assignable by design (same write path as locale); the request already
        // validated the slug against the enum, null included.
        $this->viewer()->forceFill(['avatar_color' => $request->validated('avatar_color')])->save();

        return redirect()->route('member.avatar.edit')
            ->with('status', __('Badge color updated.'));
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

    /**
     * The badge-color picker payload. `value` is the stored slug (what the POST validates), while
     * each option carries the display hex — the client must never post a hex back.
     *
     * @return array{value: string|null, options: list<array{value: string, hex: string, label: string}>}
     */
    private function badgeColor(): array
    {
        return [
            'value' => $this->viewer()->avatar_color?->value,
            'options' => array_map(
                static fn (AvatarColor $color): array => [
                    'value' => $color->value,
                    'hex' => $color->hex(),
                    'label' => $color->label(),
                ],
                AvatarColor::cases(),
            ),
        ];
    }
}
