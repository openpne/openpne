<?php

namespace App\Http\Requests\Compose;

use App\Models\Member;
use App\Support\ComposeEditor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The compose input-method POST: a member's Rich/Markdown/Plain choice for the Modern compose forms.
 */
class UpdateComposeEditorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['compose_editor' => ['required', Rule::enum(ComposeEditor::class)]];
    }
}
