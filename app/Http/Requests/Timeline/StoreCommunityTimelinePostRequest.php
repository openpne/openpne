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
            // Which of the two forms posted this, so a validation failure returns to it.
            'from' => ['nullable', 'in:new'],
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

    /**
     * A failure lands back on the form that was submitted. The community's timeline carries an
     * inline box (Classic), but the standalone compose page is its own screen — returning a
     * validation error to the list would drop the draft and the errors on a page with nowhere to
     * show them. `from` names the compose page; anything else is the inline box.
     */
    protected function getRedirectUrl(): string
    {
        $group = $this->route('group');
        $route = $this->input('from') === 'new' ? 'group.timeline.new' : 'group.timeline';

        return $this->redirector->getUrlGenerator()->route($route, ['group' => $group]);
    }
}
