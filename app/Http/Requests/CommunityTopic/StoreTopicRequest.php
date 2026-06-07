<?php

namespace App\Http\Requests\CommunityTopic;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\Data\CommunityTopicFormData;
use App\Models\Community;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a topic. Posting authority is gated in authorize() — before validation runs — so an
 * unauthorized member's invalid payload gets the same 404 as a valid one and never leaks the
 * board's posting policy (the board's "every refusal is 404" contract).
 */
class StoreTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        $community = $this->route('community');
        $viewer = $this->user();
        if (! $community instanceof Community || ! $viewer instanceof Member
            || ! CommunityTopicAccess::canPostTopic($community, $viewer)) {
            abort(404);
        }

        return true;
    }

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
