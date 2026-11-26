<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\Responses\ReturnToResetForm;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Controllers\NewPasswordController as FortifyNewPasswordController;

class NewPasswordController extends FortifyNewPasswordController
{
    public function store(Request $request): Responsable
    {
        try {
            return parent::store($request);
        } catch (ValidationException $e) {
            throw $e->redirectTo(ReturnToResetForm::formUrl($request));
        }
    }
}
