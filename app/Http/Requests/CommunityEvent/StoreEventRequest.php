<?php

namespace App\Http\Requests\CommunityEvent;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Data\CommunityEventFormData;
use App\Http\Requests\Concerns\PostImageRules;
use App\Models\Group;
use App\Models\Member;
use App\Rules\MaxBytes;
use App\Support\BodyFormat;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create an event. Posting authority is gated in authorize() — before validation runs — so an
 * unauthorized member's invalid payload gets the same 404 as a valid one and never leaks the
 * community's posting policy ("every refusal is 404").
 */
class StoreEventRequest extends FormRequest
{
    private const BODY_MAX_BYTES = 65535;

    public function authorize(): bool
    {
        $group = $this->route('group');
        $viewer = $this->user();
        if (! $group instanceof Group || ! $viewer instanceof Member
            || ! CommunityEventAccess::canPostEvent($group, $viewer)) {
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
            'name' => ['required', 'string'],
            // Bounded by bytes, not characters: the body lives in a TEXT column (65535 bytes), and
            // MySQL rejects anything longer at insert time. The cap equals the column size, so no
            // migrated value can be locked out of re-editing.
            'body' => ['required', 'string', new MaxBytes(self::BODY_MAX_BYTES)],
            'area' => ['required', 'string'],
            'open_date' => $this->openDateRules(),
            'open_date_comment' => ['string'],
            // Date-only (no time): OpenPNE 3's form is a date widget, and isClosed/isExpired add a
            // whole day, so a time component would shift the join window.
            'application_deadline' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            // op3 is never author-able: it exists only on bodies migrated from OpenPNE 3.
            'format' => ['sometimes', Rule::in([BodyFormat::Plain->value, BodyFormat::Markdown->value])],
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
            format: isset($v['format']) ? BodyFormat::from($v['format']) : null,
        );
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return PostImageRules::attributes();
    }
}
