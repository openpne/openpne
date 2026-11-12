<?php

namespace App\Features\Member;

use App\Auth\Reauth\PasskeyProof;
use App\Auth\Reauth\ReauthPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\PasskeyProofRequest;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\Exception\WebauthnException;

/** See docs/internals/security.md, "Member re-authentication". */
class ReauthPasskeyController extends Controller
{
    /** Not the package's `passkey.verification_options`, which the login screen rewrites on every render. */
    public const OPTIONS_SLOT = 'reauth.passkey_options';

    public const PURPOSE_ATTRIBUTE = 'reauth.purpose';

    public const CEREMONY_SECONDS = 600;

    public function options(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $purpose = ReauthPurpose::tryFrom((string) $request->query('purpose'));
        $viewer = $this->viewer();

        // With no passkey the options would list no credential, which asks the browser for any one.
        abort_if($purpose === null || ! $viewer->passkeys()->exists(), 404);

        $options = $generate($viewer);

        $request->session()->put(self::OPTIONS_SLOT, [
            'options' => WebAuthn::toJson($options),
            'purpose' => $purpose->value,
            'at' => now()->getTimestamp(),
        ]);

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function store(PasskeyProofRequest $request, VerifyPasskey $verify): JsonResponse
    {
        $viewer = $this->viewer();

        try {
            [$options, $purpose] = $request->ceremony();

            // Read by LogPasskeyVerified off the container's request, since the package's event
            // carries only the member and the passkey.
            request()->attributes->set(self::PURPOSE_ATTRIBUTE, $purpose->value);

            // The session is saved whole, so a request that began before the pull can write the slot
            // back; the cache refuses the challenge a second time, for longer than the slot stays fresh.
            if (! Cache::add('reauth-ceremony|'.$viewer->getKey().'|'.hash('sha256', $options->challenge), 1, self::CEREMONY_SECONDS * 2)) {
                throw ValidationException::withMessages(['credential' => __('Passkey verification session expired. Please try again.')]);
            }

            $verify($request->credential(), $options, $viewer);
        } catch (ValidationException|WebauthnException $e) {
            SecurityLog::event('passkey.failed', [
                'guard' => 'member',
                'credential_id' => Base64UrlSafe::encodeUnpadded($request->credential()->rawId),
                'member_id' => $viewer->getKey(),
                'via' => 'reauth',
            ]);

            throw $e instanceof ValidationException ? $e : ValidationException::withMessages([
                'credential' => __('Unable to verify passkey. Please try again.'),
            ]);
        }

        PasskeyProof::issue($request->session(), $viewer, $purpose);

        return response()->json(['purpose' => $purpose->value]);
    }
}
