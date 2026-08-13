<?php

namespace App\Http\Requests\GroupTopic;

use App\Features\GroupTopic\GroupTopicAccess;
use App\Http\Requests\Concerns\PostImageRules;
use App\Models\GroupTopic;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

class StoreTopicCommentRequest extends FormRequest
{
    /**
     * Gate commenting (membership + topic existence) before validation, so a non-member or a
     * request against a missing topic gets a uniform 404 regardless of payload validity.
     */
    public function authorize(): bool
    {
        $topic = GroupTopic::find($this->route('topic'));
        $viewer = $this->user();
        if (! $topic instanceof GroupTopic || ! $viewer instanceof Member
            || ! GroupTopicAccess::canComment($topic, $viewer)) {
            abort(404);
        }

        return true;
    }

    /**
     * OpenPNE 3 right-trims the comment body (opValidatorString rtrim) before validating, so a
     * whitespace-only comment is rejected as empty rather than stored blank.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => rtrim($this->input('body'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // No max length: OpenPNE 3 comment body is TEXT with no validator limit.
            'body' => ['required', 'string'],
            ...PostImageRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return PostImageRules::attributes();
    }
}
