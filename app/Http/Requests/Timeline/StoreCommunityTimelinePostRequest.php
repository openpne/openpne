<?php

namespace App\Http\Requests\Timeline;

use App\Features\Timeline\Data\TimelinePostFormData;
use App\Http\Requests\Concerns\MentionRules;
use App\Http\Requests\Concerns\PostImageRules;
use App\Support\Visibility;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Posting into a community's timeline. Same body, image and mention rules as the SNS-wide form,
 * without the two things a community post does not choose: the audience is the community, and the
 * page to return to is the community's own timeline rather than an allowlisted token.
 */
class StoreCommunityTimelinePostRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => MentionRules::normalizeNewlines($this->input('body'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // OpenPNE 3 activity_data.body is string(140).
            'body' => ['required', 'string', 'max:140'],
            'image' => PostImageRules::single(),
            ...MentionRules::rules(),
        ];
    }

    public function toData(): TimelinePostFormData
    {
        $validated = $this->validated();

        return new TimelinePostFormData(
            body: $validated['body'],
            // The action fixes this too; passing it here keeps the Data object honest rather than
            // carrying a value the write would contradict.
            visibility: Visibility::Members,
            mentions: MentionRules::normalize($validated['mentions'] ?? []),
        );
    }

    /** A failure lands back on the community's timeline, where the form is. */
    protected function getRedirectUrl(): string
    {
        return $this->redirector->getUrlGenerator()
            ->route('community.timeline', ['community' => $this->route('community')]);
    }
}
