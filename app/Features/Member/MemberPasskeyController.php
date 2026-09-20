<?php

namespace App\Features\Member;

use App\Features\Member\Actions\DeleteMemberPasskey;
use App\Features\Member\Actions\OpenPasskeyReauth;
use App\Features\Member\Actions\RegisterMemberPasskey;
use App\Features\Member\Serializers\MemberPasskeySerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\DeletePasskeyRequest;
use App\Http\Requests\Member\PasskeyReauthRequest;
use App\Http\Requests\Member\PasskeyStoreRequest;
use App\Notifications\Member\PasskeyRegisteredNotification;
use App\Notifications\Member\PasskeyRemovedNotification;
use App\Support\SecurityLog;
use App\Support\SurfaceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Support\WebAuthn;

/** See docs/internals/security.md, "Member passkeys". */
class MemberPasskeyController extends Controller
{
    public function edit(Request $request): InertiaResponse
    {
        return Inertia::render('member/config/passkeys', MemberPasskeySerializer::state($this->viewer(), $request->session()));
    }

    public function reauth(PasskeyReauthRequest $request, OpenPasskeyReauth $open): RedirectResponse
    {
        $open(
            $this->viewer(),
            $request->requiresSecondFactor(),
            $request->validated('code'),
            $request->validated('recovery_code'),
        );

        PasskeyReauth::stamp($request->session());

        return $this->passkeyRedirect($request);
    }

    public function options(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        abort_unless(PasskeyReauth::isFresh($request->session()), 403);

        $options = $generate($this->viewer());

        // The same session key the vendor request reads back (PasskeyRegistrationRequest::registrationOptions).
        $request->session()->put('passkey.registration_options', WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function store(PasskeyStoreRequest $request, RegisterMemberPasskey $register): JsonResponse
    {
        $viewer = $this->viewer();

        $passkey = $register($viewer, $request->string('name')->toString(), $request->credential(), $request->registrationOptions());

        PasskeyReauth::clear($request->session());

        // Logged before the fallible enqueue, which must not suppress the audit record.
        SecurityLog::event('passkey.registered', ['guard' => 'member', 'member_id' => $viewer->getKey(), 'passkey_id' => $passkey->getKey()]);
        $viewer->notify(new PasskeyRegisteredNotification($viewer->locale ?? app()->getLocale()));

        return response()->json(['id' => $passkey->getKey(), 'name' => $passkey->name]);
    }

    public function destroy(DeletePasskeyRequest $request, DeleteMemberPasskey $delete, int $id): RedirectResponse
    {
        $viewer = $this->viewer();

        $passkey = $delete($viewer, $id, $request->session()->getId());
        abort_if($passkey === null, 404);

        SecurityLog::event('passkey.removed', ['guard' => 'member', 'member_id' => $viewer->getKey(), 'passkey_id' => $passkey->getKey()]);
        $viewer->notify(new PasskeyRemovedNotification($viewer->locale ?? app()->getLocale()));

        return $this->passkeyRedirect($request, __('The passkey has been removed.'));
    }

    private function passkeyRedirect(Request $request, ?string $status = null): RedirectResponse
    {
        $redirect = SurfaceResolver::resolve($request, 'member') === SurfaceResolver::CLASSIC
            ? redirect()->route('member.config', ['category' => MemberConfigCategory::Passkey->value])
            : redirect()->route('member.config.passkeys.edit');

        return $status === null ? $redirect : $redirect->with('status', $status);
    }
}
