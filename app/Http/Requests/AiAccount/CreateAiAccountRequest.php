<?php

declare(strict_types=1);

namespace App\Http\Requests\AiAccount;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The one field a member fills in to create an AI account. An AI account is a member row, so the
 * name is held to the same rule registration holds a person's to (CreateNewMember).
 */
class CreateAiAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255']];
    }
}
