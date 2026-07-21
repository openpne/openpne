<?php

namespace App\Features\Compose;

use App\Http\Controllers\Controller;
use App\Http\Requests\Compose\UpdateComposeEditorRequest;
use App\Support\ComposeEditor;
use Illuminate\Http\Response;

/**
 * Persists a member's compose-editor choice (Rich/Markdown) for the Modern compose forms. The client
 * is a fire-and-forget fetch, not an Inertia visit, so it returns 204 with no body.
 */
class EditorPreferenceController extends Controller
{
    public function update(UpdateComposeEditorRequest $request): Response
    {
        $this->viewer()->setComposeEditor(ComposeEditor::from($request->validated('compose_editor')));

        return response()->noContent();
    }
}
