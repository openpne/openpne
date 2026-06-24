<?php

namespace App\Http\Requests\Member;

use App\Features\Diary\DiaryVisibility;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The diary section of the member config page: the default audience pre-selected on the new-diary
 * form. Restricted to the currently selectable audiences (DiaryVisibility::rule() drops Open when
 * web-public is off), the same rule the diary post form uses.
 */
class UpdateDiaryDefaultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['diary_default_visibility' => ['required', DiaryVisibility::rule()]];
    }
}
