<?php

namespace App\Features\Compose;

use App\Http\Controllers\Controller;
use App\Support\MarkdownText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-side Markdown preview for the compose forms (diary / topic / event). A cross-feature
 * endpoint: one renderer backs every body, so the preview lives here rather than under a single
 * feature. It runs the same sanitized MarkdownText pipeline as a stored render, so the preview can
 * never show markup the saved body would strip.
 */
class PreviewController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        $request->validate(['body' => ['required', 'string']]);

        return response()->json(['html' => MarkdownText::render($request->string('body')->value())->toHtml()]);
    }
}
