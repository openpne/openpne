<?php

namespace App\Http\Requests\GroupEvent;

use App\Features\GroupEvent\GroupEventAccess;
use App\Http\Requests\Concerns\PostImageRules;
use App\Models\GroupEvent;
use App\Models\Member;
use App\Rules\MaxBytes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * In OpenPNE 3 the comment body is required even when the submit is an RSVP, so joining always comes
 * with a comment. Membership and event existence are gated in authorize() for a uniform 404.
 */
class StoreEventCommentRequest extends FormRequest
{
    // Bytes, not characters, equal to the TEXT column MySQL enforces at insert.
    private const BODY_MAX_BYTES = 65535;

    public function authorize(): bool
    {
        $event = GroupEvent::find($this->route('event'));
        $viewer = $this->user();
        if (! $event instanceof GroupEvent || ! $viewer instanceof Member
            || ! GroupEventAccess::canComment($event, $viewer)) {
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
            'body' => ['required', 'string', new MaxBytes(self::BODY_MAX_BYTES)],
            ...PostImageRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return PostImageRules::attributes();
    }
}
