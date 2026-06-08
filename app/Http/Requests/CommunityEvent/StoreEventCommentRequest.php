<?php

namespace App\Http\Requests\CommunityEvent;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Models\CommunityEvent;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Post an event comment. In OpenPNE 3 the comment body is required even when the submit is an RSVP
 * (participate/cancel) — the same form carries both, so joining always comes with a comment.
 * Membership + event existence are gated in authorize() for a uniform 404.
 */
class StoreEventCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = CommunityEvent::find($this->route('event'));
        $viewer = $this->user();
        if (! $event instanceof CommunityEvent || ! $viewer instanceof Member
            || ! CommunityEventAccess::canComment($event, $viewer)) {
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
        ];
    }
}
