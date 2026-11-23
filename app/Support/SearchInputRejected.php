<?php

namespace App\Support;

use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SearchInputRejected extends DomainException
{
    public function report(): void {}

    public function render(Request $request): Response
    {
        return $request->expectsJson()
            ? response()->json(['message' => $this->getMessage()], 422)
            : redirect()->to(url()->current())->with('error', $this->getMessage());
    }
}
