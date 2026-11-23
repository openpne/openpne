<?php

namespace App\Http\Requests\DirectMessage;

use App\Features\DirectMessage\DirectMessageComposeData;
use App\Http\Requests\Concerns\PostImageRules;
use App\Models\DirectMessage;
use App\Rules\MaxBytes;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Only the shape of `to` is validated here: the action re-checks the recipient and the send gate. */
class ComposeDirectMessageRequest extends FormRequest
{
    // Bytes, not characters, equal to the TEXT column MySQL enforces at insert.
    private const BODY_MAX_BYTES = 65535;

    public function authorize(): bool
    {
        return true;
    }

    /** OpenPNE 3 right-trims subject/body before validating, so whitespace-only is rejected as empty. */
    protected function prepareForValidation(): void
    {
        foreach (['subject', 'body'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => rtrim($this->input($field))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to' => ['required', 'integer', 'exists:members,id'],
            'subject' => ['required', 'string', new MaxBytes(self::BODY_MAX_BYTES)],
            'body' => ['required', 'string', new MaxBytes(self::BODY_MAX_BYTES)],
            // A reply link must point at a message the viewer is a party to, not just any message id,
            // so a crafted parent_id/thread_id can never reference a stranger's message.
            'parent_id' => ['nullable', 'integer', $this->partyToMessage()],
            'thread_id' => ['nullable', 'integer', $this->partyToMessage()],
            ...PostImageRules::rules(),
        ];
    }

    /** Fails unless the value is a message the viewer sent or received. */
    private function partyToMessage(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $viewerId = $this->user()?->getKey();
            $isParty = DirectMessage::whereKey($value)
                ->where(fn ($q) => $q->where('sender_id', $viewerId)
                    ->orWhereHas('recipients', fn ($r) => $r->where('recipient_id', $viewerId)))
                ->exists();

            if (! $isParty) {
                $fail('The selected :attribute is invalid.');
            }
        };
    }

    public function asDraft(): bool
    {
        return $this->input('action') === 'draft';
    }

    public function toData(): DirectMessageComposeData
    {
        $v = $this->validated();

        return new DirectMessageComposeData(
            recipientId: (int) $v['to'],
            subject: $v['subject'],
            body: $v['body'],
            parentId: isset($v['parent_id']) ? (int) $v['parent_id'] : null,
            threadId: isset($v['thread_id']) ? (int) $v['thread_id'] : null,
        );
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return PostImageRules::attributes();
    }
}
