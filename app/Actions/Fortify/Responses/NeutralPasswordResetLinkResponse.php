<?php

namespace App\Actions\Fortify\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One identical response for the success and the failure contract, so the forgot-password
 * endpoint never reveals which addresses are registered. A malformed-email validation error is
 * raised before the broker and reveals nothing, so it is left as is.
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
