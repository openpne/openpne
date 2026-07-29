<?php

namespace App\Http\Requests\Timeline;

use App\Features\Timeline\Data\TimelinePostFormData;
use App\Features\Timeline\TimelineVisibility;
use App\Http\Requests\Concerns\PostImageRules;
use App\Support\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTimelinePostRequest extends FormRequest
{
    /**
     * Where each inline compose form returns to, keyed by its return_to token. An allowlist,
     * never the Referer: a Referer-derived redirect is attacker-influenced, and the token also
     * pins "back" to page 1, where the fresh post actually is.
     */
    private const RETURN_ROUTES = [
        'index' => 'timeline.index',
        'home' => 'home',
    ];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // OpenPNE 3 activity_data.body is string(140).
            'body' => ['required', 'string', 'max:140'],
            'visibility' => ['required', TimelineVisibility::rule()],
            'image' => PostImageRules::single(),
            'return_to' => ['nullable', Rule::in(array_keys(self::RETURN_ROUTES))],
        ];
    }

    /** The route the posting page returns to on success, or null for the standalone form. */
    public function returnRoute(): ?string
    {
        $token = $this->validated()['return_to'] ?? null;

        return $token === null ? null : self::RETURN_ROUTES[$token];
    }

    /**
     * Validation failures land back on the form deterministically — the inline form's page via
     * its token, the standalone compose page otherwise — instead of the Referer-driven default.
     */
    protected function getRedirectUrl(): string
    {
        $route = self::RETURN_ROUTES[$this->input('return_to')] ?? 'timeline.new';

        return route($route);
    }

    public function toData(): TimelinePostFormData
    {
        $validated = $this->validated();

        return new TimelinePostFormData(
            body: $validated['body'],
            visibility: Visibility::from($validated['visibility']),
        );
    }
}
