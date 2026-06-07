<?php

namespace App\Http\Requests\CommunityTopic;

use App\Features\CommunityTopic\Data\CommunityTopicFormData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create/update a topic. Whether the actor may post (or edit) is enforced in the
 * controller/action via CommunityTopicAccess.
 */
class StoreTopicRequest extends FormRequest
{
    /**
     * OpenPNE 3 right-trims string fields (opValidatorString rtrim) before validating, so a
     * whitespace-only name or body is rejected as empty rather than stored blank.
     */
    protected function prepareForValidation(): void
    {
        foreach (['name', 'body'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => rtrim($this->input($field))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // No max length: OpenPNE 3 community_topic name/body are TEXT with no validator limit.
            'name' => ['required', 'string'],
            'body' => ['required', 'string'],
        ];
    }

    public function toData(): CommunityTopicFormData
    {
        $validated = $this->validated();

        return new CommunityTopicFormData(
            name: $validated['name'],
            body: $validated['body'],
        );
    }
}
