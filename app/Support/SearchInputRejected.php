<?php

namespace App\Support;

use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Answered as an input error and not logged: the same screen without its query, the reason in the flash. */
final class SearchInputRejected extends DomainException
{
    public function report(): void {}

    public function render(Request $request): Response
    {
        return $request->expectsJson()
            ? response()->json(['message' => $this->getMessage()], 422)
            : redirect()->to($request->url())->with('error', $this->getMessage());
    }
}
