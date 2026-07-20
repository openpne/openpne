<?php

namespace App\Http\Requests\CommunityTopic;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\Data\CommunityTopicFormData;
use App\Http\Requests\Concerns\PostImageRules;
use App\Models\Community;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        return [...$this->textRules(), ...PostImageRules::rules()];
    }

    /**
     * The text fields, shared with editing (UpdateTopicRequest). No max length: OpenPNE 3
     * community_topic name/body are TEXT with no validator limit.
     *
     * @return array<string, mixed>
     */
    protected function textRules(): array
    {
        return [
            'name' => ['required', 'string'],
            'body' => ['required', 'string'],
            // op3 is never author-able: it exists only on bodies migrated from OpenPNE 3.
            'format' => ['sometimes', Rule::in([BodyFormat::Plain->value, BodyFormat::Markdown->value])],
        ];
    }

    public function toData(): CommunityTopicFormData
    {
        $validated = $this->validated();

        return new CommunityTopicFormData(
            name: $validated['name'],
            body: $validated['body'],
            format: isset($validated['format']) ? BodyFormat::from($validated['format']) : null,
        );
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return PostImageRules::attributes();
    }
}
