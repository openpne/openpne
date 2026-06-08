<?php

namespace App\Actions\Fortify\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One identical neutral response for both the success and failure (unknown address / throttled)
 * Fortify contracts, so the forgot-password endpoint never reveals which addresses are registered.
 * Bound for both contracts in FortifyServiceProvider. A malformed-email validation error is raised
 * before the broker and reveals nothing about existence, so it is left untouched.
 */
class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function toResponse($request): Response
    {
        $message = __('passwords.neutral');

        return $request->wantsJson()
            ? new JsonResponse(['status' => $message], 200)
            : back()->with('status', $message);
    }
}
