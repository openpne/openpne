<?php

namespace App\Features\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\DismissRowActionsHintRequest;
use Illuminate\Http\Response;

/** The client is a fire-and-forget fetch rather than an Inertia visit, so this answers 204 with no body. */
class RowActionsHintController extends Controller
{
    public function dismiss(DismissRowActionsHintRequest $request): Response
    {
        $this->viewer()->dismissRowActionsHint();

        return response()->noContent();
    }
}
