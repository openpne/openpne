<?php

namespace App\Actions\Fortify\Responses;

use Illuminate\Http\Request;
use Laravel\Fortify\Http\Responses\FailedPasswordResetResponse;

/** A refused reset names its form, since the session never recorded that URL (docs/internals/sessions.md, "The previous URL"). */
class ReturnToResetForm extends FailedPasswordResetResponse
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return parent::toResponse($request);
        }

        return redirect()->to(self::formUrl($request))
            ->withInput($request->only('email'))
            ->withErrors(['email' => trans($this->status)]);
    }

    public static function formUrl(Request $request): string
    {
        $token = $request->input('token');
        $email = $request->input('email');

        return is_string($token) && $token !== ''
            ? route('password.reset', ['token' => $token, 'email' => is_string($email) ? $email : null])
            : route('password.request');
    }
}
