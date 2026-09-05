<?php

namespace App\Features\Compose;

use App\Http\Controllers\Controller;
use App\Http\Requests\Compose\UpdateComposeEditorRequest;
use App\Support\ComposeEditor;
use Illuminate\Http\Response;

/** The client is a fire-and-forget fetch rather than an Inertia visit, so this answers 204 with no body. */
class EditorPreferenceController extends Controller
{
    public function update(UpdateComposeEditorRequest $request): Response
    {
        $this->viewer()->setComposeEditor(ComposeEditor::from($request->validated('compose_editor')));

        return response()->noContent();
    }
}
