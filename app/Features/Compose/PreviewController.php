<?php

namespace App\Features\Compose;

use App\Http\Controllers\Controller;
use App\Rules\MaxBytes;
use App\Support\MarkdownText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Runs the same MarkdownText pipeline as a stored render, so the preview can never show markup the
 * saved body would strip (`docs/internals/body-text.md`, "`markdown` — two independent safety belts").
 */
class PreviewController extends Controller
{
    // The same raw-input cap the markdown-capable body rules apply (the TEXT column's 65,535 bytes).
    private const BODY_MAX_BYTES = 65535;

    public function preview(Request $request): JsonResponse
    {
        $request->validate(['body' => ['required', 'string', new MaxBytes(self::BODY_MAX_BYTES)]]);

        return response()->json(['html' => MarkdownText::render($request->string('body')->value())->toHtml()]);
    }
}
