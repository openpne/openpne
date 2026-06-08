<?php

namespace App\Http\Requests\CommunityEvent;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Data\CommunityEventFormData;
use App\Http\Requests\Concerns\PostImageRules;
use App\Models\Community;
use App\Models\Member;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create an event. Posting authority is gated in authorize() — before validation runs — so an
 * unauthorized member's invalid payload gets the same 404 as a valid one and never leaks the
 * community's posting policy ("every refusal is 404").
 */
class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $community = $this->route('community');
        $viewer = $this->user();
        if (! $community instanceof Community || ! $viewer instanceof Member
            || ! CommunityEventAccess::canPostEvent($community, $viewer)) {
            abort(404);
        }

        return true;
    }

    /**
     * OpenPNE 3 right-trims string fields (opValidatorString rtrim). open_date_comment is optional;
     * OpenPNE 3 stores '' rather than null when it is omitted.
     */
    protected function prepareForValidation(): void
    {
        foreach (['name', 'body', 'area'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => rtrim($this->input($field))]);
            }
        }
        // Coerce a missing comment to '' (OpenPNE 3 stores empty, not null). A non-string (e.g. an
        // array) is left untouched so the string rule rejects it rather than being cast to "Array".
        $comment = $this->input('open_date_comment');
        if ($comment === null) {
            $this->merge(['open_date_comment' => '']);
        } elseif (is_string($comment)) {
            $this->merge(['open_date_comment' => rtrim($comment)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // No max length: OpenPNE 3 name/body/area are TEXT with no validator limit.
            'name' => ['required', 'string'],
            'body' => ['required', 'string'],
            'area' => ['required', 'string'],
            'open_date' => $this->openDateRules(),
            'open_date_comment' => ['string'],
            // Date-only (no time): OpenPNE 3's form is a date widget, and isClosed/isExpired add a
            // whole day, so a time component would shift the join window.
            'application_deadline' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            ...PostImageRules::rules(),
        ];
    }

    /** On create, OpenPNE 3 requires the open date to be today or later; editing lifts that. */
    protected function openDateRules(): array
    {
        return ['required', 'date_format:Y-m-d', 'after_or_equal:today'];
    }

    /** OpenPNE 3 validateApplicationDeadline: a deadline, if set, must be on or before the open date. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $openDate = $this->input('open_date');
            $deadline = $this->input('application_deadline');
            if ($openDate && $deadline && strtotime((string) $deadline) > strtotime((string) $openDate)) {
                $validator->errors()->add('application_deadline', __('The application deadline must be on or before the open date.'));
            }
        });
    }

    public function toData(): CommunityEventFormData
    {
        $v = $this->validated();

        return new CommunityEventFormData(
            name: $v['name'],
            body: $v['body'],
            open_date: $v['open_date'],
            open_date_comment: $v['open_date_comment'] ?? '',
            area: $v['area'],
            application_deadline: $v['application_deadline'] ?? null,
            capacity: isset($v['capacity']) ? (int) $v['capacity'] : null,
        );
    }
}
